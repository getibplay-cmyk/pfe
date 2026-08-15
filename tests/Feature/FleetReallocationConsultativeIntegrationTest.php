<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\FleetReallocationDecision;
use App\Models\FleetReallocationMove;
use App\Models\FleetReallocationProposal;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Intelligence\FleetReallocation\FleetReallocationCanonicalPayload;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetReallocationConsultativeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-15 12:00:00+00:00');
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_imports_downloads_and_reviews_a_qualified_synthetic_plan_without_operational_write(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload();
        $trackedTables = [
            'vehicles',
            'vehicle_blocks',
            'reservations',
            'rental_contracts',
            'maintenance_orders',
            'invoices',
            'payments',
        ];
        $before = collect($trackedTables)->mapWithKeys(
            static fn (string $table): array => [$table => DB::table($table)->count()],
        );

        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.fleet-reallocation.store'), [
                'proposal' => $this->jsonFile($payload),
            ])
            ->assertRedirect(route('intelligence.fleet-reallocation.index'))
            ->assertSessionHas('status', 'Proposition OR-Tools synthétique importée sans effet opérationnel.');

        $proposal = FleetReallocationProposal::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($payload['proposal_id'], $proposal->proposal_id);
        $this->assertSame(FleetReallocationContract::SOLVER_STATUS, $proposal->solver_status);
        $this->assertSame(FleetReallocationContract::FORECAST_MODEL, $proposal->forecast_model_name);
        $this->assertSame(FleetReallocationContract::CANCELLATION_DECISION, $proposal->cancellation_gate_decision);
        $this->assertSame(FleetReallocationContract::PRESENCE_PROBABILITY, $proposal->presence_probability);
        $this->assertSame(FleetReallocationContract::LOCAL_VALIDATION_STATUS, $proposal->local_validation_status);
        $this->assertSame(FleetReallocationContract::OPERATIONAL_EFFECT, $proposal->operational_effect);
        $this->assertSame('pending', $proposal->reviewStatus());
        $this->assertSame(1, $proposal->move_line_count);

        $move = FleetReallocationMove::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('SYNTH-NODE-001', $move->from_node_ref);
        $this->assertSame('SYNTH-NODE-002', $move->to_node_ref);
        $this->assertSame('7.500', $move->distance_km);
        $this->assertSame(2, $move->vehicles);
        $this->assertSame(7500, $move->total_cost_centimes);
        Storage::disk('local')->assertExists($proposal->stored_path);
        $canonical = Storage::disk('local')->get($proposal->stored_path);
        $this->assertSame($proposal->byte_size, strlen($canonical));
        $this->assertSame($proposal->content_sha256, hash('sha256', $canonical));

        $audit = AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.fleet_reallocation.imported')
            ->firstOrFail();
        foreach (['stored_path', 'idempotency_key', 'canonical_payload_sha256', 'content_sha256'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $audit->new_values);
        }

        $this->actingAs($fixture['owner'])
            ->get(route('intelligence.fleet-reallocation.index'))
            ->assertOk()
            ->assertSee('Propositions de réallocation OR-Tools')
            ->assertSee('SYNTH-NODE-001')
            ->assertSee('CatBoost s’abstient')
            ->assertDontSee($proposal->stored_path)
            ->assertDontSee($proposal->content_sha256);

        $download = $this->actingAs($fixture['owner'])
            ->get(route('intelligence.fleet-reallocation.download', $proposal))
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-rentfleet-reallocation-proposal', $proposal->proposal_id);
        $this->assertSame($canonical, $download->streamedContent());

        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.fleet-reallocation.decisions.store', $proposal), [
                'decision' => 'accepted_for_demo_review',
                'reason_code' => 'DEMO_REJECTED',
            ])
            ->assertSessionHasErrors('reason_code');
        $this->assertSame(0, FleetReallocationDecision::withoutGlobalScopes()->count());

        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.fleet-reallocation.decisions.store', $proposal), [
                'decision' => 'accepted_for_demo_review',
                'reason_code' => 'CONSULTATIVE_PLAN_ACCEPTED_FOR_DEMO',
            ])
            ->assertRedirect(route('intelligence.fleet-reallocation.index'));

        $decision = FleetReallocationDecision::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('accepted_for_demo_review', $decision->decision->value);
        $this->assertSame(FleetReallocationContract::OPERATIONAL_EFFECT, $decision->effect);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_same_key_same_payload_replays_but_different_payload_conflicts(): void
    {
        $fixture = $this->fixture();
        $payload = $this->payload();

        foreach ([1, 2] as $attempt) {
            $this->actingAs($fixture['owner'])
                ->post(route('intelligence.fleet-reallocation.store'), [
                    'proposal' => $this->jsonFile($payload, 'proposal-'.$attempt.'.json'),
                ])
                ->assertRedirect(route('intelligence.fleet-reallocation.index'));
        }
        $this->assertSame(1, FleetReallocationProposal::withoutGlobalScopes()->count());
        $this->assertSame(1, FleetReallocationMove::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.fleet_reallocation.replayed']);

        $conflict = $payload;
        $conflict['proposal_id'] = (string) Str::uuid();
        $conflict = $this->withDigest($conflict);
        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.fleet-reallocation.store'), [
                'proposal' => $this->jsonFile($conflict, 'conflict.json'),
            ])
            ->assertStatus(409);
        $this->assertSame(1, FleetReallocationProposal::withoutGlobalScopes()->count());
    }

    public function test_contract_refuses_miles_catboost_discount_unknown_keys_wrong_cost_and_slow_solver(): void
    {
        $fixture = $this->fixture();

        $invalidPayloads = [];
        $miles = $this->payload();
        $miles['planning']['distance_unit'] = 'mi';
        $invalidPayloads[] = $miles;

        $discount = $this->payload();
        $discount['planning']['cancellation_risk']['presence_probability'] = '0.850000';
        $invalidPayloads[] = $discount;

        $unknown = $this->payload();
        $unknown['tenant_id'] = $fixture['tenant']->id;
        $invalidPayloads[] = $unknown;

        $wrongCost = $this->payload();
        $wrongCost['planning']['moves'][0]['unit_cost_centimes'] = 3749;
        $invalidPayloads[] = $wrongCost;

        $slow = $this->payload();
        $slow['summary']['solver_runtime_ms'] = '5000.000001';
        $invalidPayloads[] = $slow;

        foreach ($invalidPayloads as $position => $invalid) {
            $this->actingAs($fixture['owner'])
                ->post(route('intelligence.fleet-reallocation.store'), [
                    'proposal' => $this->jsonFile($this->withDigest($invalid), 'invalid-'.$position.'.json'),
                ])
                ->assertSessionHasErrors('proposal');
        }

        $this->assertSame(0, FleetReallocationProposal::withoutGlobalScopes()->count());
        $this->assertSame(0, FleetReallocationMove::withoutGlobalScopes()->count());
    }

    public function test_tenant_scope_and_tenant_wide_rbac_reject_cross_tenant_and_agency_users(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.fleet-reallocation.store'), [
                'proposal' => $this->jsonFile($this->payload()),
            ])
            ->assertRedirect();
        $proposal = FleetReallocationProposal::withoutGlobalScopes()->firstOrFail();

        foreach (['agency-manager', 'fleet-manager', 'viewer-auditor'] as $role) {
            $user = $this->user($fixture['tenant'], $fixture['agency_a'], $role);
            $this->actingAs($user)
                ->get(route('intelligence.fleet-reallocation.index'))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('intelligence.fleet-reallocation.store'), [
                    'proposal' => $this->jsonFile($this->payload()),
                ])
                ->assertForbidden();
        }

        $foreign = $this->fixture();
        $this->actingAs($foreign['owner'])
            ->get(route('intelligence.fleet-reallocation.download', $proposal))
            ->assertNotFound();

        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.fleet-reallocation.store'), [
                'proposal' => $this->jsonFile($this->payload()),
                'tenant_id' => $foreign['tenant']->id,
                'agency_id' => $foreign['agency_a']->id,
            ])
            ->assertSessionHasErrors(['tenant_id', 'agency_id']);
    }

    public function test_private_artifact_integrity_and_append_only_postgresql_guards_are_enforced(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.fleet-reallocation.store'), [
                'proposal' => $this->jsonFile($this->payload()),
            ])
            ->assertRedirect();
        $proposal = FleetReallocationProposal::withoutGlobalScopes()->firstOrFail();

        foreach ([
            'fleet_reallocation_proposals_append_only',
            'fleet_reallocation_moves_append_only',
            'fleet_reallocation_decisions_append_only',
            'fleet_reallocation_proposals_completeness_guard',
        ] as $trigger) {
            $this->assertTrue(DB::table('information_schema.triggers')
                ->where('trigger_schema', 'public')
                ->where('trigger_name', $trigger)
                ->exists(), $trigger);
        }

        $this->assertPostgreSqlConstraint(fn () => DB::table('fleet_reallocation_proposals')
            ->where('id', $proposal->id)
            ->update(['service_rate' => '0.100000']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('fleet_reallocation_moves')
            ->where('fleet_reallocation_proposal_id', $proposal->id)
            ->delete());

        Storage::disk('local')->put($proposal->stored_path, '{}');
        $this->actingAs($fixture['owner'])
            ->get(route('intelligence.fleet-reallocation.download', $proposal))
            ->assertStatus(409);
        Storage::disk('local')->delete($proposal->stored_path);
        $this->actingAs($fixture['owner'])
            ->get(route('intelligence.fleet-reallocation.download', $proposal))
            ->assertStatus(410);
    }

    public function test_routes_and_fail_closed_configuration_are_explicit(): void
    {
        foreach ([
            'intelligence.fleet-reallocation.index',
            'intelligence.fleet-reallocation.store',
            'intelligence.fleet-reallocation.download',
            'intelligence.fleet-reallocation.decisions.store',
        ] as $route) {
            $this->assertTrue(app('router')->has($route), $route);
        }

        $this->assertTrue((bool) config('intelligence.fleet_reallocation.synthetic_demo_only'));
        $this->assertFalse((bool) config('intelligence.fleet_reallocation.automatic_actions_allowed'));
        $this->assertFalse((bool) config('intelligence.fleet_reallocation.operational_table_writes_allowed'));
        $this->assertSame(
            FleetReallocationContract::LOCAL_VALIDATION_STATUS,
            config('intelligence.fleet_reallocation.local_validation_status'),
        );
        $this->assertSame(
            FleetReallocationContract::OPERATIONAL_EFFECT,
            config('intelligence.fleet_reallocation.decision_effect'),
        );
    }

    /** @return array{tenant: Tenant, agency_a: Agency, agency_b: Agency, owner: User} */
    private function fixture(): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Entreprise fictive OR-Tools',
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ]);
        [$agencyA, $agencyB] = app(TenantContext::class)->run(
            $tenant,
            fn (): array => [
                Agency::factory()->create(['name' => 'Agence fictive A']),
                Agency::factory()->create(['name' => 'Agence fictive B']),
            ],
        );
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);

        return [
            'tenant' => $tenant,
            'agency_a' => $agencyA,
            'agency_b' => $agencyB,
            'owner' => $owner,
        ];
    }

    private function user(Tenant $tenant, Agency $agency, string $roleSlug): User
    {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $agency->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'must_change_password' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $payload = [
            'schema_version' => FleetReallocationContract::SCHEMA_VERSION,
            'proposal_id' => (string) Str::uuid(),
            'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'source' => [
                'kind' => FleetReallocationContract::SOURCE_KIND,
                'solver_name' => FleetReallocationContract::SOLVER_NAME,
                'solver_version' => FleetReallocationContract::SOLVER_VERSION,
                'solver_status' => FleetReallocationContract::SOLVER_STATUS,
                'qualification_decision' => FleetReallocationContract::QUALIFICATION_DECISION,
                'qualification_commit' => FleetReallocationContract::QUALIFICATION_COMMIT,
                'evidence_commit' => FleetReallocationContract::EVIDENCE_COMMIT,
            ],
            'planning' => [
                'as_of_date' => '2026-08-15',
                'target_date' => '2026-08-16',
                'forecast_horizon' => 1,
                'distance_unit' => FleetReallocationContract::DISTANCE_UNIT,
                'data_status' => FleetReallocationContract::DATA_STATUS,
                'demand_source' => [
                    'model_name' => FleetReallocationContract::FORECAST_MODEL,
                    'model_version' => FleetReallocationContract::FORECAST_VERSION,
                    'forecast_reference_sha256' => str_repeat('a', 64),
                    'local_holdout_status' => FleetReallocationContract::FORECAST_LOCAL_STATUS,
                    'synthetic_demo' => true,
                ],
                'cancellation_risk' => [
                    'model_name' => FleetReallocationContract::CANCELLATION_MODEL,
                    'gate_decision' => FleetReallocationContract::CANCELLATION_DECISION,
                    'presence_probability' => FleetReallocationContract::PRESENCE_PROBABILITY,
                    'presence_reason' => FleetReallocationContract::PRESENCE_REASON,
                    'demand_adjustment' => 'ABSTENTION_NO_DEMAND_REDUCTION',
                ],
                'nodes' => [
                    [
                        'node_ref' => 'SYNTH-NODE-001',
                        'available_vehicles' => 6,
                        'forecast_demand' => 2,
                        'effective_demand' => 2,
                    ],
                    [
                        'node_ref' => 'SYNTH-NODE-002',
                        'available_vehicles' => 1,
                        'forecast_demand' => 4,
                        'effective_demand' => 4,
                    ],
                ],
                'moves' => [
                    [
                        'from_node_ref' => 'SYNTH-NODE-001',
                        'to_node_ref' => 'SYNTH-NODE-002',
                        'vehicles' => 2,
                        'distance_km' => '7.500',
                        'unit_cost_centimes' => 3750,
                        'total_cost_centimes' => 7500,
                        'reason_code' => FleetReallocationContract::MOVE_REASON,
                        'operational_effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                    ],
                ],
            ],
            'summary' => [
                'node_count' => 2,
                'move_line_count' => 1,
                'relocated_vehicle_count' => 2,
                'total_demand' => 6,
                'served_demand' => 6,
                'unserved_demand' => 0,
                'service_rate' => '1.000000',
                'relocation_cost_centimes' => 7500,
                'decision_cost_centimes' => 7500,
                'solver_runtime_ms' => '0.032959',
            ],
            'safety' => [
                'synthetic_demo' => true,
                'contains_real_customer_data' => false,
                'contains_direct_identifiers' => false,
                'contains_coordinates' => false,
                'human_decision_required' => true,
                'automatic_action_allowed' => false,
                'operational_table_write_allowed' => false,
                'local_validation_status' => FleetReallocationContract::LOCAL_VALIDATION_STATUS,
                'operational_effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ],
            'idempotency' => [
                'key' => (string) Str::uuid(),
                'policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY',
                'canonical_payload_sha256' => str_repeat('0', 64),
            ],
        ];

        return $this->withDigest($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withDigest(array $payload): array
    {
        $payload['idempotency']['canonical_payload_sha256'] = app(FleetReallocationCanonicalPayload::class)
            ->digest($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function jsonFile(array $payload, string $name = 'fleet-reallocation-proposal.json'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    private function assertPostgreSqlConstraint(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Une contrainte PostgreSQL devait refuser cette mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }
}
