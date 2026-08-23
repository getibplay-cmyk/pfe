<?php

namespace Tests\Feature;

use App\Enums\J11AdvisoryModule;
use App\Models\Agency;
use App\Models\AiAdvisoryRecordDemo;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Intelligence\J11\J11SyntheticFixtureRepository;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
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

    public function test_tenant_wide_null_agency_scope_is_server_scoped_idempotent_audited_and_non_operational(): void
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
            'agency_id' => null,
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

    public function test_same_fixture_is_independent_and_idempotent_per_agency_without_cross_agency_replay_or_read(): void
    {
        $this->enableHarness();
        $fixture = $this->fixture();
        $fleetA = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);
        $fleetB = $this->user($fixture, 'fleet-manager', $fixture['agency_b']);
        $managerB = $this->user($fixture, 'agency-manager', $fixture['agency_b']);
        $module = J11AdvisoryModule::PredictiveMaintenance;

        $this->actingAs($fleetA)
            ->post(route('intelligence.contract-demo.fixtures.store'), ['module_id' => $module->value])
            ->assertRedirect(route('intelligence.contract-demo.index'));

        $recordA = AiAdvisoryRecordDemo::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_a']->id)
            ->where('module_id', $module->value)
            ->firstOrFail();

        $this->actingAs($managerB)
            ->get(route('intelligence.contract-demo.index'))
            ->assertOk()
            ->assertDontSee($module->label());

        $this->actingAs($fleetB)
            ->post(route('intelligence.contract-demo.decisions.store', $recordA), [
                'decision' => 'rejected',
                'reason_code' => 'DEMO_REJECTED',
            ])
            ->assertForbidden();

        $this->actingAs($fleetB)
            ->post(route('intelligence.contract-demo.fixtures.store'), ['module_id' => $module->value])
            ->assertRedirect(route('intelligence.contract-demo.index'));

        $recordB = AiAdvisoryRecordDemo::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_b']->id)
            ->where('module_id', $module->value)
            ->firstOrFail();

        $this->assertNotSame($recordA->id, $recordB->id);
        $this->assertSame($fleetA->id, $recordA->created_by);
        $this->assertSame($fleetB->id, $recordB->created_by);
        $this->assertDatabaseHas('ai_idempotency_keys_demo', [
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency_a']->id,
            'ai_advisory_record_demo_id' => $recordA->id,
        ]);
        $this->assertDatabaseHas('ai_idempotency_keys_demo', [
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency_b']->id,
            'ai_advisory_record_demo_id' => $recordB->id,
        ]);

        foreach ([$fleetA, $fleetB] as $fleetManager) {
            $this->actingAs($fleetManager)
                ->post(route('intelligence.contract-demo.fixtures.store'), ['module_id' => $module->value])
                ->assertRedirect(route('intelligence.contract-demo.index'));
        }

        $this->assertSame(2, AiAdvisoryRecordDemo::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('module_id', $module->value)
            ->count());
        $this->assertSame(2, DB::table('ai_idempotency_keys_demo')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('idempotency_key', '10000000-0000-4000-8000-000000000003')
            ->count());
        $this->assertSame(2, AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('action', 'prediction.demo.fixture_imported')
            ->count());
        $this->assertSame(2, AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('action', 'prediction.demo.fixture_replayed')
            ->count());
    }

    public function test_two_concurrent_first_imports_in_the_same_scope_create_once_and_replay_once(): void
    {
        $this->enableHarness();
        $fixture = $this->fixture();
        $fleetA = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);
        $database = $this->assertUsesAuthorizedPostgreSqlTestDatabase();
        $idempotencyKey = '10000000-0000-4000-8000-000000000003';
        $lockKey = implode('|', [
            'j12',
            $fixture['tenant']->id,
            $fixture['agency_a']->id,
            $idempotencyKey,
        ]);

        DB::connection()->commit();
        DB::connection()->beginTransaction();
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            [$lockKey],
        );

        $pool = null;
        $waitingProcesses = 0;
        $observedActivities = [];

        try {
            $command = [
                PHP_BINARY,
                base_path('tests/Support/run-j12-concurrent-import.php'),
                (string) $fleetA->id,
                J11AdvisoryModule::PredictiveMaintenance->value,
            ];
            $environment = [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => $database,
            ];

            $pool = Process::pool(function (Pool $pool) use ($command, $environment): void {
                foreach (['first', 'second'] as $name) {
                    $pool->as($name)
                        ->path(base_path())
                        ->env($environment)
                        ->timeout(30)
                        ->command($command);
                }
            })->start();

            $deadline = microtime(true) + 10;
            do {
                DB::selectOne('SELECT pg_stat_clear_snapshot()');
                $waitingProcesses = (int) DB::scalar(<<<'SQL'
                    SELECT count(*)
                    FROM pg_stat_activity
                    WHERE datname = current_database()
                        AND pid <> pg_backend_pid()
                        AND wait_event_type = 'Lock'
                        AND wait_event = 'advisory'
                        AND query LIKE '%pg_advisory_xact_lock%'
                    SQL);

                $observedActivities = DB::select(<<<'SQL'
                    SELECT application_name, state, wait_event_type, wait_event
                    FROM pg_stat_activity
                    WHERE datname = current_database()
                        AND pid <> pg_backend_pid()
                    ORDER BY pid
                    SQL);

                if ($waitingProcesses === 2) {
                    break;
                }

                usleep(50_000);
            } while (microtime(true) < $deadline);
        } finally {
            DB::connection()->commit();
        }

        $results = $pool?->wait();
        $this->assertNotNull($results);
        $this->assertTrue($results->successful(), $results->collect()
            ->map(fn ($result): string => $result->errorOutput())
            ->filter()
            ->implode(PHP_EOL));

        $payloads = $results->collect()
            ->map(fn ($result): array => json_decode(
                trim($result->output()),
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
        $this->assertSame([$database], $payloads->pluck('database')->unique()->values()->all());
        $this->assertSame(
            2,
            $waitingProcesses,
            'Les deux imports doivent attendre simultanément le verrou J12. Activités observées : '
                .json_encode($observedActivities, JSON_THROW_ON_ERROR),
        );
        $this->assertSame([false, true], $payloads->pluck('created')->sort()->values()->all());
        $this->assertCount(1, $payloads->pluck('record_id')->unique());
        $this->assertSame(1, DB::table('ai_advisory_records_demo')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_a']->id)
            ->where('module_id', J11AdvisoryModule::PredictiveMaintenance->value)
            ->count());
        $this->assertSame(1, DB::table('ai_idempotency_keys_demo')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_a']->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_a']->id)
            ->where('action', 'prediction.demo.fixture_imported')
            ->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_a']->id)
            ->where('action', 'prediction.demo.fixture_replayed')
            ->count());
    }

    public function test_contract_demo_html_never_contains_a_complete_or_abbreviated_fingerprint(): void
    {
        $this->enableHarness();
        $fixture = $this->fixture();

        foreach (J11AdvisoryModule::cases() as $module) {
            $this->actingAs($fixture['owner'])
                ->post(route('intelligence.contract-demo.fixtures.store'), ['module_id' => $module->value])
                ->assertRedirect(route('intelligence.contract-demo.index'));
        }

        $records = AiAdvisoryRecordDemo::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->get();
        $this->assertCount(4, $records);

        $response = $this->actingAs($fixture['owner'])
            ->get(route('intelligence.contract-demo.index'))
            ->assertOk()
            ->assertSee(J11AdvisoryModule::DemandForecast->label())
            ->assertDontSee('Empreinte');

        foreach ($records as $record) {
            $response->assertDontSee($record->fingerprint, false);
            $response->assertDontSee(substr($record->fingerprint, 0, 12), false);
        }

        $visibleText = preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($response->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
        $this->assertIsString($visibleText);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![0-9a-f])[0-9a-f]{12,64}(?:…|\.\.\.)?(?![0-9a-f])/i',
            $visibleText,
        );
    }

    public function test_postgresql_rejects_idempotency_links_outside_the_null_safe_scope(): void
    {
        $this->enableHarness();
        $fixture = $this->fixture();
        $fleetA = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);

        $this->actingAs($fleetA)
            ->post(route('intelligence.contract-demo.fixtures.store'), [
                'module_id' => J11AdvisoryModule::DemandForecast->value,
            ])
            ->assertRedirect(route('intelligence.contract-demo.index'));

        $record = AiAdvisoryRecordDemo::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_a']->id)
            ->firstOrFail();
        $key = [
            'tenant_id' => $fixture['tenant']->id,
            'ai_advisory_record_demo_id' => $record->id,
            'idempotency_key' => '10000000-0000-4000-8000-000000000001',
            'fingerprint' => $record->fingerprint,
            'first_result' => 'CREATED',
            'created_at' => now(),
        ];

        $this->expectDatabaseError('23514', fn () => DB::table('ai_idempotency_keys_demo')->insert([
            ...$key,
            'agency_id' => $fixture['agency_b']->id,
        ]));
        $this->expectDatabaseError('23514', fn () => DB::table('ai_idempotency_keys_demo')->insert([
            ...$key,
            'agency_id' => null,
        ]));
        $this->expectDatabaseError('23505', fn () => DB::table('ai_idempotency_keys_demo')->insert([
            ...$key,
            'agency_id' => $fixture['agency_a']->id,
        ]));
    }

    public function test_scope_migration_backfills_an_existing_idempotency_key_without_data_loss(): void
    {
        $this->enableHarness();
        $fixture = $this->fixture();
        $fleetA = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);

        $this->actingAs($fleetA)
            ->post(route('intelligence.contract-demo.fixtures.store'), [
                'module_id' => J11AdvisoryModule::RentalUsageAnomaly->value,
            ])
            ->assertRedirect(route('intelligence.contract-demo.index'));

        $record = AiAdvisoryRecordDemo::withoutGlobalScopes()
            ->where('tenant_id', $fixture['tenant']->id)
            ->where('agency_id', $fixture['agency_a']->id)
            ->firstOrFail();
        $migration = require database_path(
            'migrations/2026_08_12_000001_harden_ai_contract_demo_idempotency_scope.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ai_idempotency_keys_demo', 'agency_id'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('ai_idempotency_keys_demo', 'agency_id'));
        $this->assertDatabaseHas('ai_advisory_records_demo', [
            'id' => $record->id,
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency_a']->id,
        ]);
        $this->assertDatabaseHas('ai_idempotency_keys_demo', [
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency_a']->id,
            'ai_advisory_record_demo_id' => $record->id,
            'idempotency_key' => '10000000-0000-4000-8000-000000000004',
        ]);
        $this->assertSame(1, DB::table('ai_advisory_records_demo')->count());
        $this->assertSame(1, DB::table('ai_idempotency_keys_demo')->count());
    }

    public function test_scope_migration_refuses_an_existing_inconsistent_idempotency_link(): void
    {
        $fixture = $this->fixture();
        $fleetA = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);
        $sealed = app(J11SyntheticFixtureRepository::class)
            ->get(J11AdvisoryModule::FleetOptimization);
        $migration = require database_path(
            'migrations/2026_08_12_000001_harden_ai_contract_demo_idempotency_scope.php',
        );

        $migration->down();

        $record = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => AiAdvisoryRecordDemo::create([
                'agency_id' => $fixture['agency_a']->id,
                'external_record_id' => $sealed->recordId,
                'module_id' => $sealed->module,
                'contract_version' => '1.0.0',
                'source_kind' => 'synthetic_fixture',
                'payload' => $sealed->payload,
                'fingerprint' => $sealed->fingerprint,
                'validation_status' => 'validated',
                'operational_effect' => 'NO_OPERATIONAL_ACTION',
                'created_by' => $fleetA->id,
                'created_at' => now(),
            ]),
            $fixture['agency_a']->id,
        );
        DB::table('ai_idempotency_keys_demo')->insert([
            'tenant_id' => $fixture['tenant']->id,
            'ai_advisory_record_demo_id' => $record->id,
            'idempotency_key' => $sealed->idempotencyKey,
            'fingerprint' => str_repeat('0', 64),
            'first_result' => 'CREATED',
            'created_at' => now(),
        ]);

        $this->expectDatabaseError('23514', fn () => $migration->up());

        $this->assertFalse(Schema::hasColumn('ai_idempotency_keys_demo', 'agency_id'));
        $this->assertSame(1, DB::table('ai_advisory_records_demo')->count());
        $this->assertSame(1, DB::table('ai_idempotency_keys_demo')->count());
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
        $this->assertSame(80, DB::table('migrations')->count());

        foreach (['ai_advisory_records_demo', 'ai_idempotency_keys_demo', 'ai_human_decisions_demo'] as $table) {
            $this->assertTrue(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists());
        }

        $advisoryUnique = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            ['ai_advisory_demo_external_unique'],
        );
        $idempotencyUnique = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            ['ai_idempotency_demo_tenant_key_unique'],
        );
        $agencyForeignKey = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            ['ai_idempotency_demo_agency_fk'],
        );

        $this->assertStringContainsString(
            'UNIQUE NULLS NOT DISTINCT (tenant_id, agency_id, module_id, external_record_id)',
            $advisoryUnique->definition,
        );
        $this->assertStringContainsString(
            'UNIQUE NULLS NOT DISTINCT (tenant_id, agency_id, idempotency_key)',
            $idempotencyUnique->definition,
        );
        $this->assertStringContainsString(
            'FOREIGN KEY (tenant_id, agency_id) REFERENCES agencies(tenant_id, id) ON DELETE CASCADE',
            $agencyForeignKey->definition,
        );
        $this->assertTrue(DB::table('pg_trigger')
            ->where('tgname', 'ai_idempotency_demo_scope_guard')
            ->where('tgisinternal', false)
            ->exists());

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
        $this->expectDatabaseError('23514', $callback);
    }

    private function expectDatabaseError(string $expectedCode, callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Une contrainte PostgreSQL J12 devait refuser cette mutation.');
        } catch (QueryException $exception) {
            $this->assertSame($expectedCode, $exception->getCode());
        }
    }
}
