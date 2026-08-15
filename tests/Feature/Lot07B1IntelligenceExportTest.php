<?php

namespace Tests\Feature;

use App\Enums\RentalContractStatus;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IntelligenceDatasetExportRun;
use App\Models\Permission;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleInspection;
use App\Support\Intelligence\PredictionInput;
use App\Support\Tenancy\TenantContext;
use App\Support\Ui\NavigationBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Lot07B1IntelligenceExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['intelligence.export_hmac_key' => str_repeat('hmac-test-only-', 4)]);
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_page_and_export_follow_the_six_role_rbac_matrix(): void
    {
        $expectations = [
            'tenant-owner' => [200, 200],
            'agency-manager' => [200, 200],
            'rental-agent' => [403, 403],
            'fleet-manager' => [200, 403],
            'accountant' => [403, 403],
            'viewer-auditor' => [200, 403],
        ];

        foreach ($expectations as $role => [$pageStatus, $exportStatus]) {
            $fixture = $this->fixture($role);
            $filters = $this->filters($fixture['agency']);
            $this->actingAs($fixture['user'])->get(route('intelligence.index', $filters))->assertStatus($pageStatus);
            $this->actingAs($fixture['user'])->get(route('intelligence.export', $filters))->assertStatus($exportStatus);
        }
    }

    public function test_real_export_has_exact_schema_formulas_headers_and_no_sensitive_content(): void
    {
        $fixture = $this->fixture();
        $eligible = $this->contract($fixture, RentalContractStatus::Returned, [
            'actual_start_at' => '2026-08-10 10:00:00+00',
            'expected_return_at' => '2026-08-11 10:00:00+00',
            'actual_return_at' => '2026-08-11 16:00:00+00',
            'start_mileage' => 1000,
            'return_mileage' => 1750,
            'start_fuel_level' => '80.00',
            'return_fuel_level' => '55.00',
        ]);
        $this->returnInspection($fixture, $eligible);

        $response = $this->actingAs($fixture['user'])->get(route('intelligence.export', $this->filters($fixture['agency'])));
        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = preg_split('/\r\n|\n|\r/', substr($content, 3), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertCount(2, $lines);
        $this->assertSame(PredictionInput::headers(), str_getcsv($lines[0], ';', '"', ''));
        $row = array_combine(PredictionInput::headers(), str_getcsv($lines[1], ';', '"', ''));

        $this->assertSame('1.1', $row['schema_version']);
        $this->assertSame('rentfleet-real-returns-v1.1.0', $row['dataset_version']);
        $this->assertSame('2026-08-11T15:00:00Z', $row['event_at']);
        $this->assertSame('6.000000', $row['late_hours']);
        $this->assertSame('600.000000', $row['km_per_day']);
        $this->assertSame('25.000000', $row['fuel_drop_pct']);
        $this->assertMatchesRegularExpression('/^r_[a-f0-9]{64}$/', $row['row_id']);

        foreach ([
            'Personne Fictive',
            'qa07b1@example.invalid',
            'QA-07B1-REGISTRATION',
            'QA07B1VIN000000001',
            '=NOTE_NON_EXPORTEE',
            'label',
            'score',
            'responsibility',
            'payment',
            'invoice',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    public function test_incomplete_active_return_pending_and_out_of_period_contracts_are_excluded(): void
    {
        $fixture = $this->fixture();
        $eligible = $this->contract($fixture, RentalContractStatus::Returned);
        $this->returnInspection($fixture, $eligible);

        foreach ([RentalContractStatus::Active, RentalContractStatus::ReturnPending] as $status) {
            $contract = $this->contract($fixture, $status);
            $this->returnInspection($fixture, $contract);
        }
        $this->contract($fixture, RentalContractStatus::Returned);
        $outside = $this->contract($fixture, RentalContractStatus::Returned, [
            'expected_start_at' => '2025-01-01 10:00:00+00',
            'actual_start_at' => '2025-01-01 10:00:00+00',
            'expected_return_at' => '2025-01-02 10:00:00+00',
            'actual_return_at' => '2025-01-02 10:00:00+00',
            'returned_at' => '2025-01-02 10:00:00+00',
        ]);
        $this->returnInspection($fixture, $outside);

        $content = $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', substr($content, 3), -1, PREG_SPLIT_NO_EMPTY);

        $this->assertCount(2, $lines);
    }

    public function test_tenant_and_agency_scope_cannot_be_forged_and_foreign_data_is_absent(): void
    {
        $fixture = $this->fixture('tenant-owner');
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $own = $this->contract($fixture, RentalContractStatus::Returned);
        $this->returnInspection($fixture, $own);
        $otherAgencyFixture = $this->businessFixtureForAgency($fixture, $otherAgency);
        $otherAgencyContract = $this->contract($otherAgencyFixture, RentalContractStatus::Returned);
        $this->returnInspection($otherAgencyFixture, $otherAgencyContract);

        $foreign = $this->fixture();
        $foreignContract = $this->contract($foreign, RentalContractStatus::Returned);
        $this->returnInspection($foreign, $foreignContract);

        $manager = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency']->id,
            'role_id' => Role::where('slug', 'agency-manager')->value('id'),
            'must_change_password' => false,
        ]);

        $this->actingAs($manager)
            ->get(route('intelligence.export', $this->filters($otherAgency)))
            ->assertForbidden();
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', [...$this->filters($fixture['agency']), 'tenant_id' => $foreign['tenant']->id]))
            ->assertSessionHasErrors('tenant_id');

        $content = $this->actingAs($manager)
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', substr($content, 3), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertCount(2, $lines);
        $this->assertStringNotContainsString('Tenant B', $content);
    }

    public function test_missing_hmac_key_fails_closed_without_secret_disclosure(): void
    {
        $fixture = $this->fixture();
        config(['intelligence.export_hmac_key' => '']);

        $response = $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])));

        $response->assertServiceUnavailable();
        $response->assertDontSee('INTELLIGENCE_EXPORT_HMAC_KEY');
        $response->assertDontSee('hmac-test-only');
    }

    public function test_period_limit_audit_navigation_migration_and_postgresql_guards_are_enforced(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['user'])->get(route('intelligence.export', [
            'date_from' => '2025-01-01',
            'date_to' => '2026-01-02',
            'agency_id' => $fixture['agency']->id,
        ]))->assertSessionHasErrors('date_to');

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->assertOk();
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'prediction.dataset.exported')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing([
            'run_id',
            'schema_version',
            'dataset_version',
            'date_from',
            'date_to',
            'scope_kind',
            'row_count',
            'max_rows',
            'format',
            'operational_effect',
        ], array_keys($audit->new_values));
        foreach (['content', 'content_sha256', 'stored_path', 'scope_key', 'row_id', 'contract_id', 'contract_key', 'secret', 'late_hours', 'km_per_day', 'fuel_drop_pct'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $audit->new_values);
        }

        $navigation = app(NavigationBuilder::class)->for($fixture['user']);
        $items = collect($navigation)->flatMap(fn (array $section) => $section['items']);
        $this->assertTrue($items->contains(fn (array $item) => $item['key'] === 'intelligence' && $item['route'] === 'intelligence.index'));
        $this->actingAs($fixture['user'])->get(route('intelligence.index', $this->filters($fixture['agency'])))
            ->assertOk()
            ->assertSee('data-nav-key="intelligence"', false);

        $this->assertDatabaseHas('permissions', ['slug' => 'prediction.export', 'group' => 'prediction']);
        $this->assertTrue(DB::table('pg_indexes')->where('indexname', 'rental_contracts_intelligence_export_idx')->exists());
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
        $this->assertSame(78, DB::table('migrations')->count());
    }

    public function test_j14_export_creates_a_private_reproducible_snapshot_and_closed_manifest(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 10:00:00+01:00');
        $fixture = $this->fixture();
        $contract = $this->contract($fixture, RentalContractStatus::Returned);
        $this->returnInspection($fixture, $contract);

        $firstResponse = $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->assertOk();
        $firstContent = $firstResponse->streamedContent();
        $firstRun = IntelligenceDatasetExportRun::withoutGlobalScopes()->latest('id')->firstOrFail();

        $firstResponse->assertHeader('x-rentfleet-export-run', $firstRun->run_id)
            ->assertHeader('x-rentfleet-snapshot-sha256', $firstRun->content_sha256);
        Storage::disk('local')->assertExists($firstRun->stored_path);
        $this->assertSame($firstContent, Storage::disk('local')->get($firstRun->stored_path));
        $this->assertSame(hash('sha256', $firstContent), $firstRun->content_sha256);
        $this->assertSame(strlen($firstContent), $firstRun->byte_size);
        $this->assertSame(1, $firstRun->row_count);
        $this->assertSame('agency', $firstRun->scope_kind);
        $this->assertMatchesRegularExpression('/^a_[a-f0-9]{64}$/', $firstRun->scope_key);
        $this->assertSame('NO_OPERATIONAL_ACTION', $firstRun->operational_effect);

        $secondResponse = $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->assertOk();
        $secondContent = $secondResponse->streamedContent();
        $secondRun = IntelligenceDatasetExportRun::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertNotSame($firstRun->run_id, $secondRun->run_id);
        $this->assertSame($firstContent, $secondContent);
        $this->assertSame($firstRun->content_sha256, $secondRun->content_sha256);
        $this->assertSame(2, IntelligenceDatasetExportRun::withoutGlobalScopes()->count());

        $manifestResponse = $this->actingAs($fixture['user'])
            ->get(route('intelligence.exports.manifest', $firstRun))
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8');
        $manifestContent = $manifestResponse->streamedContent();
        $manifest = json_decode($manifestContent, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('1.0.0', $manifest['manifest_version']);
        $this->assertSame($firstRun->run_id, $manifest['run_id']);
        $this->assertSame(PredictionInput::SCHEMA_VERSION, $manifest['dataset']['schema_version']);
        $this->assertSame(PredictionInput::DATASET_VERSION, $manifest['dataset']['dataset_version']);
        $this->assertSame($firstRun->content_sha256, $manifest['snapshot']['content_sha256']);
        $this->assertSame(1, $manifest['snapshot']['row_count']);
        $this->assertTrue($manifest['safety']['pseudonymized']);
        $this->assertFalse($manifest['safety']['contains_predictions']);
        $this->assertSame('NO_OPERATIONAL_ACTION', $manifest['safety']['operational_effect']);
        foreach (['stored_path', 'created_by', 'tenant_id', 'agency_id', 'Personne Fictive', 'qa07b1@example.invalid'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $manifestContent);
        }

        $snapshotResponse = $this->actingAs($fixture['user'])
            ->get(route('intelligence.exports.download', $firstRun))
            ->assertOk()
            ->assertHeader('x-rentfleet-export-run', $firstRun->run_id);
        $this->assertSame($firstContent, $snapshotResponse->streamedContent());
        $page = $this->actingAs($fixture['user'])
            ->get(route('intelligence.index', $this->filters($fixture['agency'])))
            ->assertOk()
            ->assertSee('J14-A · snapshots d’export reproductibles')
            ->assertSee($firstRun->run_id)
            ->assertSee('Manifeste JSON')
            ->assertSee('Snapshot CSV');
        $page->assertDontSee($firstRun->content_sha256)
            ->assertDontSee($firstRun->stored_path);
    }

    public function test_j14_rbac_scope_immutability_and_snapshot_integrity_fail_closed(): void
    {
        $fixture = $this->fixture('agency-manager');
        $contract = $this->contract($fixture, RentalContractStatus::Returned);
        $this->returnInspection($fixture, $contract);
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.export', $this->filters($fixture['agency'])))
            ->assertOk()
            ->streamedContent();
        $run = IntelligenceDatasetExportRun::withoutGlobalScopes()->latest('id')->firstOrFail();

        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $otherManager = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $otherAgency->id,
            'role_id' => Role::where('slug', 'agency-manager')->value('id'),
            'must_change_password' => false,
        ]);
        $viewer = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency']->id,
            'role_id' => Role::where('slug', 'viewer-auditor')->value('id'),
            'must_change_password' => false,
        ]);
        $owner = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);
        $foreign = $this->fixture();

        $this->actingAs($otherManager)
            ->get(route('intelligence.exports.manifest', $run))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('intelligence.exports.download', $run))
            ->assertForbidden();
        $this->actingAs($foreign['user'])
            ->get(route('intelligence.exports.manifest', $run))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('intelligence.exports.manifest', $run))
            ->assertOk()
            ->streamedContent();

        $this->assertPostgreSqlConstraint(fn () => DB::table('intelligence_dataset_export_runs')
            ->where('id', $run->id)
            ->update(['row_count' => 999]));
        $this->assertPostgreSqlConstraint(fn () => DB::table('intelligence_dataset_export_runs')
            ->where('id', $run->id)
            ->delete());

        $downloadsBefore = AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.dataset.snapshot_downloaded')
            ->count();
        Storage::disk('local')->put($run->stored_path, 'snapshot-altéré');
        $this->actingAs($owner)
            ->get(route('intelligence.exports.download', $run))
            ->assertStatus(409);
        $this->assertSame($downloadsBefore, AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.dataset.snapshot_downloaded')
            ->count());
    }

    public function test_j14_failed_manifest_persistence_removes_the_private_snapshot(): void
    {
        $fixture = $this->fixture();
        config(['intelligence.dataset_exports.manifest_version' => 'invalid-version']);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($fixture['user'])
                ->get(route('intelligence.export', $this->filters($fixture['agency'])));
            $this->fail('La contrainte de version du manifeste devait refuser cet export.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }

        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/dataset-exports'));
        $this->assertSame(0, IntelligenceDatasetExportRun::withoutGlobalScopes()->count());
    }

    public function test_j14_postgresql_contract_and_route_surface_are_explicit(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
        $this->assertSame(78, DB::table('migrations')->count());
        $this->assertTrue(DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', 'intelligence_dataset_export_runs')
            ->exists());
        $this->assertTrue(DB::table('information_schema.triggers')
            ->where('event_object_schema', 'public')
            ->where('event_object_table', 'intelligence_dataset_export_runs')
            ->where('trigger_name', 'intelligence_export_runs_append_only')
            ->exists());

        $scopeConstraint = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            ['intelligence_export_runs_scope_check'],
        );
        $this->assertStringContainsString('scope_kind', $scopeConstraint->definition);
        $this->assertStringContainsString('tenant', $scopeConstraint->definition);
        $this->assertStringContainsString('agency', $scopeConstraint->definition);
        $this->assertTrue(app('router')->has('intelligence.exports.manifest'));
        $this->assertTrue(app('router')->has('intelligence.exports.download'));
    }

    public function test_fresh_rbac_matrix_preserves_custom_roles_and_has_no_ml_tables(): void
    {
        $expected = [
            'tenant-owner' => [true, true],
            'agency-manager' => [true, true],
            'rental-agent' => [false, false],
            'fleet-manager' => [true, false],
            'accountant' => [false, false],
            'viewer-auditor' => [true, false],
        ];
        foreach ($expected as $slug => [$view, $export]) {
            $permissions = Role::where('slug', $slug)->firstOrFail()->permissions->pluck('slug');
            $this->assertSame($view, $permissions->contains('prediction.view'), $slug.' view');
            $this->assertSame($export, $permissions->contains('prediction.export'), $slug.' export');
        }

        $tenant = Tenant::factory()->create();
        $custom = Role::forceCreate([
            'tenant_id' => $tenant->id,
            'name' => 'Rôle fictif Intelligence',
            'slug' => 'custom-intelligence',
            'is_system' => false,
            'is_active' => true,
        ]);
        $custom->permissions()->attach(Permission::where('slug', 'prediction.view')->value('id'));
        $this->seed(RolesPermissionsSeeder::class);
        $this->assertDatabaseHas('roles', ['id' => $custom->id, 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $custom->id,
            'permission_id' => Permission::where('slug', 'prediction.view')->value('id'),
        ]);

        foreach (['ml_models', 'ml_predictions', 'import_batches', 'import_rows'] as $table) {
            $this->assertFalse(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists());
        }
    }

    private function fixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Entreprise fictive 07B1',
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ]);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create(['name' => 'Agence fictive 07B1']));
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id,
            'role_id' => $role->id,
            'must_change_password' => false,
        ]);

        return $this->businessFixtureForAgency(compact('tenant', 'agency', 'user'), $agency);
    }

    private function businessFixtureForAgency(array $fixture, Agency $agency): array
    {
        return app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture, $agency): array {
            $category = VehicleCategory::create([
                'code' => 'QA07-'.str()->random(8),
                'name' => 'Catégorie fictive 07B1',
                'is_active' => true,
            ]);
            $customer = Customer::create([
                'agency_id' => $agency->id,
                'customer_type' => 'individual',
                'first_name' => 'Personne',
                'last_name' => 'Fictive',
                'email' => 'qa07b1@example.invalid',
                'verification_status' => 'verified',
                'notes' => '=NOTE_NON_EXPORTEE',
            ]);
            $vehicle = Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'QA-07B1-REGISTRATION-'.str()->random(6),
                'vin' => 'QA07B1VIN'.str()->upper(str()->random(8)),
                'brand' => 'Marque fictive',
                'model' => 'Modèle fictif',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'manual',
                'current_mileage' => 1000,
            ]);

            return [...$fixture, 'agency' => $agency, 'category' => $category, 'customer' => $customer, 'vehicle' => $vehicle];
        }, $agency->id);
    }

    private function contract(array $fixture, RentalContractStatus $status, array $overrides = []): RentalContract
    {
        return app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture, $status, $overrides): RentalContract {
            $sequence = str()->upper(str()->random(10));
            $reservation = Reservation::create([
                'agency_id' => $fixture['agency']->id,
                'customer_id' => $fixture['customer']->id,
                'vehicle_category_id' => $fixture['category']->id,
                'vehicle_id' => $fixture['vehicle']->id,
                'reservation_number' => 'RES-QA07-'.$sequence,
                'starts_at' => '2026-08-10 10:00:00+00',
                'ends_at' => '2026-08-11 10:00:00+00',
                'status' => 'converted',
                'subtotal' => '0.00',
                'options_total' => '0.00',
                'total_amount' => '0.00',
                'deposit_amount' => '0.00',
                'currency' => 'MAD',
                'pricing_snapshot' => [],
                'notes' => '=NOTE_NON_EXPORTEE',
                'created_by' => $fixture['user']->id,
            ]);

            return RentalContract::create([
                'agency_id' => $fixture['agency']->id,
                'reservation_id' => $reservation->id,
                'customer_id' => $fixture['customer']->id,
                'vehicle_id' => $fixture['vehicle']->id,
                'contract_number' => 'CTR-QA07-'.$sequence,
                'status' => $status,
                'expected_start_at' => '2026-08-10 10:00:00+00',
                'expected_return_at' => '2026-08-11 10:00:00+00',
                'actual_start_at' => '2026-08-10 10:00:00+00',
                'actual_return_at' => '2026-08-11 16:00:00+00',
                'start_mileage' => 1000,
                'return_mileage' => 1750,
                'start_fuel_level' => '80.00',
                'return_fuel_level' => '55.00',
                'rental_subtotal' => '0.00',
                'additional_charges_total' => '0.00',
                'total_amount' => '0.00',
                'deposit_required' => '0.00',
                'currency' => 'MAD',
                'returned_at' => '2026-08-11 16:00:00+00',
                'created_by' => $fixture['user']->id,
                ...$overrides,
            ]);
        }, $fixture['agency']->id);
    }

    private function returnInspection(array $fixture, RentalContract $contract): VehicleInspection
    {
        return app(TenantContext::class)->run($fixture['tenant'], fn () => VehicleInspection::create([
            'agency_id' => $contract->agency_id,
            'rental_contract_id' => $contract->id,
            'vehicle_id' => $contract->vehicle_id,
            'inspection_type' => 'return',
            'status' => 'completed',
            'inspected_at' => $contract->actual_return_at,
            'mileage' => $contract->return_mileage,
            'fuel_level' => $contract->return_fuel_level,
            'completed_by' => $fixture['user']->id,
            'completed_at' => $contract->actual_return_at,
            'created_by' => $fixture['user']->id,
        ]), $contract->agency_id);
    }

    private function filters(Agency $agency): array
    {
        return [
            'date_from' => CarbonImmutable::parse('2026-08-01')->toDateString(),
            'date_to' => CarbonImmutable::parse('2026-08-31')->toDateString(),
            'agency_id' => $agency->id,
        ];
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
