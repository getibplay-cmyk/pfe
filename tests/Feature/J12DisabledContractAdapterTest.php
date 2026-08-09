<?php

namespace Tests\Feature;

use App\Enums\J11AdvisoryModule;
use App\Models\Agency;
use App\Models\AiAdvisoryRecordDemo;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class J12DisabledContractAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_contract_demo_routes_are_closed_by_default(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])
            ->get(route('intelligence.index'))
            ->assertOk()
            ->assertSee('Désactivé par défaut')
            ->assertDontSee('Ouvrir la démonstration isolée');

        $this->actingAs($fixture['owner'])
            ->get(route('intelligence.contract-demo.index'))
            ->assertNotFound();
        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.contract-demo.fixtures.store'), [
                'module_id' => J11AdvisoryModule::DemandForecast->value,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('ai_advisory_records_demo', 0);
        $this->assertDatabaseCount('ai_idempotency_keys_demo', 0);
        $this->assertDatabaseCount('ai_human_decisions_demo', 0);
    }

    public function test_enabled_test_harness_follows_the_six_role_rbac_matrix(): void
    {
        $this->enableHarness();
        $expectations = [
            'tenant-owner' => [200, 302],
            'agency-manager' => [200, 403],
            'rental-agent' => [403, 403],
            'fleet-manager' => [200, 302],
            'accountant' => [403, 403],
            'viewer-auditor' => [200, 403],
        ];

        foreach ($expectations as $role => [$pageStatus, $importStatus]) {
            $fixture = $this->fixture();
            $user = $this->user($fixture, $role, $role === 'tenant-owner' ? null : $fixture['agency_a']);

            $this->actingAs($user)
                ->get(route('intelligence.contract-demo.index'))
                ->assertStatus($pageStatus);
            $this->actingAs($user)
                ->post(route('intelligence.contract-demo.fixtures.store'), [
                    'module_id' => J11AdvisoryModule::DemandForecast->value,
                ])
                ->assertStatus($importStatus);
        }
    }

    public function test_exact_fixture_import_is_server_scoped_idempotent_audited_and_non_operational(): void
    {
        $this->enableHarness();
        $fixture = $this->fixture();
        $foreignTenant = Tenant::factory()->create();
        $operationalTables = [
            'vehicles',
            'reservations',
            'rental_contracts',
            'maintenance_orders',
            'vehicle_blocks',
        ];
        $before = collect($operationalTables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        );

        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.contract-demo.fixtures.store'), [
                'module_id' => J11AdvisoryModule::DemandForecast->value,
            ])
            ->assertRedirect(route('intelligence.contract-demo.index'));

        $record = AiAdvisoryRecordDemo::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($fixture['tenant']->id, $record->tenant_id);
        $this->assertNull($record->agency_id);
        $this->assertSame('synthetic_fixture', $record->source_kind);
        $this->assertSame('validated', $record->validation_status);
        $this->assertSame('NO_OPERATIONAL_ACTION', $record->operational_effect);
        $this->assertFalse($record->payload['scope']['feature_flag']['enabled']);
        $this->assertFalse($record->payload['research_status']['ready_for_saas']);
        $this->assertDatabaseHas('ai_idempotency_keys_demo', [
            'tenant_id' => $fixture['tenant']->id,
            'ai_advisory_record_demo_id' => $record->id,
            'idempotency_key' => '10000000-0000-4000-8000-000000000001',
            'fingerprint' => $record->fingerprint,
            'first_result' => 'CREATED',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $fixture['tenant']->id,
            'action' => 'prediction.demo.fixture_imported',
            'auditable_id' => $record->id,
        ]);

        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.contract-demo.fixtures.store'), [
                'module_id' => J11AdvisoryModule::DemandForecast->value,
            ])
            ->assertRedirect(route('intelligence.contract-demo.index'));
        $this->assertDatabaseCount('ai_advisory_records_demo', 1);
        $this->assertDatabaseCount('ai_idempotency_keys_demo', 1);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'prediction.demo.fixture_replayed')->count());

        $this->actingAs($fixture['owner'])
            ->post(route('intelligence.contract-demo.fixtures.store'), [
                'module_id' => J11AdvisoryModule::FleetOptimization->value,
                'tenant_id' => $foreignTenant->id,
                'payload' => ['libre' => true],
                'unexpected' => 'interdit',
            ])
            ->assertSessionHasErrors(['tenant_id', 'payload', 'unexpected']);
        $this->assertDatabaseCount('ai_advisory_records_demo', 1);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_agency_scope_human_review_and_append_only_guards_are_enforced(): void
    {
        $this->enableHarness();
        $fixture = $this->fixture();
        $fleetA = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);
        $managerA = $this->user($fixture, 'agency-manager', $fixture['agency_a']);
        $managerB = $this->user($fixture, 'agency-manager', $fixture['agency_b']);

        $this->actingAs($fleetA)
            ->post(route('intelligence.contract-demo.fixtures.store'), [
                'module_id' => J11AdvisoryModule::PredictiveMaintenance->value,
            ])
            ->assertRedirect(route('intelligence.contract-demo.index'));
        $record = AiAdvisoryRecordDemo::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($managerA)
            ->get(route('intelligence.contract-demo.index'))
            ->assertOk()
            ->assertSee('Maintenance prédictive');
        $this->actingAs($managerB)
            ->get(route('intelligence.contract-demo.index'))
            ->assertOk()
            ->assertDontSee('Maintenance prédictive');

        $decision = [
            'decision' => 'accepted_for_demo_review',
            'reason_code' => 'HUMAN_REVIEW_DEMO_ONLY',
        ];
        $this->actingAs($managerA)
            ->post(route('intelligence.contract-demo.decisions.store', $record), $decision)
            ->assertForbidden();
        $this->actingAs($fleetA)
            ->post(route('intelligence.contract-demo.decisions.store', $record), $decision)
            ->assertRedirect(route('intelligence.contract-demo.index'));
        $this->assertDatabaseHas('ai_human_decisions_demo', [
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency_a']->id,
            'ai_advisory_record_demo_id' => $record->id,
            'actor_user_id' => $fleetA->id,
            'decision' => 'accepted_for_demo_review',
            'effect' => 'NO_OPERATIONAL_ACTION',
        ]);

        $other = $this->fixture();
        $this->actingAs($other['owner'])
            ->post(route('intelligence.contract-demo.decisions.store', $record), $decision)
            ->assertNotFound();

        $this->expectConstraint(fn () => DB::table('ai_advisory_records_demo')
            ->where('id', $record->id)
            ->update(['validation_status' => 'tampered']));
        $this->expectConstraint(fn () => DB::table('ai_advisory_records_demo')
            ->where('id', $record->id)
            ->delete());
        $this->expectConstraint(fn () => DB::table('ai_human_decisions_demo')->insert([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency_b']->id,
            'ai_advisory_record_demo_id' => $record->id,
            'actor_user_id' => $fleetA->id,
            'decision' => 'rejected',
            'reason_code' => 'DEMO_REJECTED',
            'effect' => 'NO_OPERATIONAL_ACTION',
            'created_at' => now(),
        ]));
    }

    public function test_postgresql_schema_and_review_permission_remain_explicit(): void
    {
        $this->assertUsesAuthorizedPostgreSqlTestDatabase();
        $this->assertSame(72, DB::table('migrations')->count());

        foreach (['ai_advisory_records_demo', 'ai_idempotency_keys_demo', 'ai_human_decisions_demo'] as $table) {
            $this->assertTrue(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists());
        }

        $expected = [
            'tenant-owner' => true,
            'agency-manager' => false,
            'rental-agent' => false,
            'fleet-manager' => true,
            'accountant' => false,
            'viewer-auditor' => false,
        ];
        foreach ($expected as $role => $allowed) {
            $permissions = Role::where('slug', $role)->firstOrFail()->permissions->pluck('slug');
            $this->assertSame($allowed, $permissions->contains('prediction.demo.review'), $role);
        }
    }

    /** @return array{tenant: Tenant, agency_a: Agency, agency_b: Agency, owner: User} */
    private function fixture(): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Entreprise fictive J12 '.str()->random(8),
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ]);
        [$agencyA, $agencyB] = app(TenantContext::class)->run($tenant, fn (): array => [
            Agency::factory()->create(['name' => 'Agence J12 A']),
            Agency::factory()->create(['name' => 'Agence J12 B']),
        ]);
        $fixture = ['tenant' => $tenant, 'agency_a' => $agencyA, 'agency_b' => $agencyB];

        return [...$fixture, 'owner' => $this->user($fixture, 'tenant-owner')];
    }

    private function user(array $fixture, string $role, ?Agency $agency = null): User
    {
        return User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'must_change_password' => false,
        ]);
    }

    private function enableHarness(): void
    {
        config(['intelligence.contract_demo.enabled' => true]);
    }

    private function expectConstraint(callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Une contrainte PostgreSQL J12 devait refuser cette mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }
}
