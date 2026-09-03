<?php

namespace Tests\Feature;

use App\Actions\Fleet\ManageAgencyDistance;
use App\Enums\AgencyDistanceSourceType;
use App\Models\Agency;
use App\Models\AgencyDistance;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Fleet\AgencyDistanceMatrixBuilder;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\FleetReallocation\FleetReallocationReadiness;
use App\Support\Intelligence\FleetReallocation\FleetReallocationRuntimeReadiness;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AgencyDistanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-30 12:00:00+01:00');
        $this->seed(RolesPermissionsSeeder::class);
        $this->mock(FleetReallocationRuntimeReadiness::class)
            ->shouldReceive('ready')
            ->andReturnTrue();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_postgresql_constraints_enforce_direction_value_source_and_tenant_scope(): void
    {
        $fixture = $this->fixture();
        $agencyC = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence C']),
        );
        $foreign = $this->fixture();

        DB::table('agency_distances')->insert($this->row(
            $fixture,
            $fixture['agency_a'],
            $fixture['agency_b'],
            '91.250',
        ));
        DB::table('agency_distances')->insert($this->row(
            $fixture,
            $fixture['agency_b'],
            $fixture['agency_a'],
            '96.750',
        ));

        $this->assertDatabaseHas('agency_distances', [
            'from_agency_id' => $fixture['agency_a']->id,
            'to_agency_id' => $fixture['agency_b']->id,
            'distance_km' => '91.250',
        ]);
        $this->assertDatabaseHas('agency_distances', [
            'from_agency_id' => $fixture['agency_b']->id,
            'to_agency_id' => $fixture['agency_a']->id,
            'distance_km' => '96.750',
        ]);

        $this->assertSqlState('23514', fn () => DB::table('agency_distances')->insert(
            $this->row($fixture, $agencyC, $agencyC, '1.000'),
        ));
        $this->assertSqlState('23514', fn () => DB::table('agency_distances')->insert(
            $this->row($fixture, $fixture['agency_a'], $agencyC, '0.000'),
        ));
        $this->assertSqlState('23514', fn () => DB::table('agency_distances')->insert(
            $this->row($fixture, $agencyC, $fixture['agency_a'], '-1.000'),
        ));
        $this->assertSqlState('23514', fn () => DB::table('agency_distances')->insert(
            $this->row($fixture, $agencyC, $fixture['agency_b'], '10000.001'),
        ));
        $this->assertSqlState('23514', fn () => DB::table('agency_distances')->insert([
            ...$this->row($fixture, $fixture['agency_b'], $agencyC, '4.000'),
            'source_type' => 'unverified_import',
        ]));
        $this->assertSqlState('23503', fn () => DB::table('agency_distances')->insert(
            $this->row($fixture, $fixture['agency_a'], $foreign['agency_a'], '2.000'),
        ));
        $this->assertSqlState('23503', fn () => DB::table('agency_distances')->insert([
            ...$this->row($fixture, $fixture['agency_a'], $agencyC, '2.000'),
            'verified_by_user_id' => $foreign['owner']->id,
        ]));
        $this->assertSqlState('23505', fn () => DB::table('agency_distances')->insert(
            $this->row($fixture, $fixture['agency_a'], $fixture['agency_b'], '99.000'),
        ));
    }

    public function test_owner_creates_corrects_and_changes_status_with_fresh_provenance_and_audit(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])
            ->post(route('agency-distances.store'), $this->payload($fixture, [
                'distance_km' => '91.250',
                'source_reference' => 'Relevé interne du 30 août',
                'same_distance_both_ways' => '1',
            ]))
            ->assertRedirect(route('agency-distances.index'));

        $this->assertDatabaseCount('agency_distances', 2);
        $this->assertSame(2, AuditLog::withoutGlobalScopes()
            ->where('action', 'fleet.agency_distance.created')->count());
        $forward = AgencyDistance::withoutGlobalScopes()
            ->where('from_agency_id', $fixture['agency_a']->id)
            ->firstOrFail();

        $secondOwner = $this->user($fixture, 'tenant-owner', null);
        CarbonImmutable::setTestNow('2026-08-30 13:00:00+01:00');
        $this->actingAs($secondOwner)
            ->patch(route('agency-distances.update', $forward), [
                'distance_km' => '92.125',
                'source_type' => 'manual_verified',
                'source_reference' => 'Nouvelle vérification interne',
                'same_distance_both_ways' => '1',
            ])
            ->assertRedirect(route('agency-distances.index'));

        $directions = AgencyDistance::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $directions);
        foreach ($directions as $distance) {
            $this->assertSame('92.125', $distance->distance_km);
            $this->assertSame($secondOwner->id, $distance->verified_by_user_id);
            $this->assertSame('Nouvelle vérification interne', $distance->source_reference);
        }
        $this->assertSame(2, AuditLog::withoutGlobalScopes()
            ->where('action', 'fleet.agency_distance.corrected')->count());

        $this->actingAs($secondOwner)
            ->patch(route('agency-distances.deactivate', $forward))
            ->assertRedirect(route('agency-distances.index'));
        $this->assertFalse($forward->fresh()->active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.agency_distance.deactivated',
            'auditable_id' => $forward->id,
        ]);

        $this->actingAs($secondOwner)
            ->patch(route('agency-distances.activate', $forward))
            ->assertRedirect(route('agency-distances.index'));
        $this->assertTrue($forward->fresh()->active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.agency_distance.activated',
            'auditable_id' => $forward->id,
        ]);
    }

    public function test_two_way_creation_rolls_back_atomically_when_the_second_write_fails(): void
    {
        $fixture = $this->fixture();
        $calls = 0;
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->twice()->andReturnUsing(function () use (&$calls): AuditLog {
            if (++$calls === 2) {
                throw new RuntimeException('EXPECTED_SECOND_WRITE_FAILURE');
            }

            return new AuditLog;
        });
        $action = new ManageAgencyDistance($audit, app(TenantContext::class));

        try {
            app(TenantContext::class)->run(
                $fixture['tenant'],
                fn () => $action->create($this->payload($fixture, [
                    'same_distance_both_ways' => true,
                ]), $fixture['owner']),
            );
            $this->fail('La seconde écriture devait interrompre la transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('EXPECTED_SECOND_WRITE_FAILURE', $exception->getMessage());
        }

        $this->assertDatabaseCount('agency_distances', 0);
    }

    public function test_rbac_password_guard_and_cross_tenant_binding_follow_least_privilege(): void
    {
        $fixture = $this->fixture();
        $distance = $this->distance($fixture, $fixture['agency_a'], $fixture['agency_b']);

        $this->get(route('agency-distances.index'))->assertRedirect(route('login'));

        $mustChange = $this->user($fixture, 'tenant-owner', null, true);
        $this->actingAs($mustChange)
            ->get(route('agency-distances.index'))
            ->assertRedirect(route('password.change-required'));

        $fleetManager = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);
        $this->actingAs($fleetManager)
            ->get(route('agency-distances.index'))
            ->assertOk()
            ->assertSee('Distances inter-agences')
            ->assertDontSee('Retour aux propositions de démonstration');
        $this->actingAs($fleetManager)
            ->post(route('agency-distances.store'), $this->payload($fixture))
            ->assertForbidden();

        foreach (['agency-manager', 'rental-agent', 'accountant', 'viewer-auditor'] as $role) {
            $user = $this->user($fixture, $role, $fixture['agency_a']);
            $this->actingAs($user)->get(route('agency-distances.index'))->assertForbidden();
        }

        $foreign = $this->fixture();
        $this->actingAs($foreign['owner'])
            ->patch(route('agency-distances.update', $distance), [
                'distance_km' => '10.000',
                'source_type' => 'manual_verified',
                'same_distance_both_ways' => '0',
            ])
            ->assertNotFound();

        $this->actingAs($fixture['owner'])
            ->post(route('agency-distances.store'), $this->payload($fixture, [
                'from_agency_id' => $foreign['agency_a']->id,
            ]))
            ->assertSessionHasErrors('from_agency_id');
        $this->assertDatabaseCount('agency_distances', 1);
    }

    public function test_server_validation_rejects_invalid_numbers_ids_sources_and_injected_fields(): void
    {
        $fixture = $this->fixture();

        foreach (['0', '-1', '10000.001', 'NaN', 'INF'] as $invalid) {
            $this->actingAs($fixture['owner'])
                ->post(route('agency-distances.store'), $this->payload($fixture, [
                    'distance_km' => $invalid,
                ]))
                ->assertSessionHasErrors('distance_km');
        }
        $this->actingAs($fixture['owner'])
            ->post(route('agency-distances.store'), $this->payload($fixture, [
                'to_agency_id' => $fixture['agency_a']->id,
            ]))
            ->assertSessionHasErrors('to_agency_id');
        $this->actingAs($fixture['owner'])
            ->post(route('agency-distances.store'), $this->payload($fixture, [
                'source_type' => 'automatic_guess',
            ]))
            ->assertSessionHasErrors('source_type');
        $this->actingAs($fixture['owner'])
            ->post(route('agency-distances.store'), $this->payload($fixture, [
                'tenant_id' => $fixture['tenant']->id,
            ]))
            ->assertSessionHasErrors('tenant_id');
        $this->actingAs($fixture['owner'])
            ->post(route('agency-distances.store'), $this->payload($fixture, [
                'unexpected_configuration' => 'forbidden',
            ]))
            ->assertSessionHasErrors('unexpected_configuration');

        $this->assertDatabaseCount('agency_distances', 0);
    }

    public function test_matrix_is_directional_complete_only_without_fallback_and_has_a_stable_fingerprint(): void
    {
        $fixture = $this->fixture();
        $builder = app(AgencyDistanceMatrixBuilder::class);

        $empty = $this->inTenant($fixture, fn () => $builder->build(collect([
            $fixture['agency_b'], $fixture['agency_a'],
        ])));
        $sameEmpty = $this->inTenant($fixture, fn () => $builder->build(collect([
            $fixture['agency_a'], $fixture['agency_b'],
        ])));
        $this->assertSame('incomplete', $empty->status);
        $this->assertCount(2, $empty->missingPairs);
        $this->assertSame('0.000', $empty->matrix[$fixture['agency_a']->id][$fixture['agency_a']->id]);
        $this->assertArrayNotHasKey($fixture['agency_b']->id, $empty->matrix[$fixture['agency_a']->id]);
        $this->assertSame($empty->fingerprint, $sameEmpty->fingerprint);

        $forward = $this->distance(
            $fixture,
            $fixture['agency_a'],
            $fixture['agency_b'],
            '91.250',
        );
        $oneWay = $this->inTenant($fixture, fn () => $builder->build(collect([
            $fixture['agency_a'], $fixture['agency_b'],
        ])));
        $this->assertSame('incomplete', $oneWay->status);
        $this->assertSame([[
            'from_agency_id' => $fixture['agency_b']->id,
            'to_agency_id' => $fixture['agency_a']->id,
        ]], $oneWay->missingPairs);

        $reverse = $this->distance(
            $fixture,
            $fixture['agency_b'],
            $fixture['agency_a'],
            '96.750',
        );
        $complete = $this->inTenant($fixture, fn () => $builder->build(collect([
            $fixture['agency_b'], $fixture['agency_a'],
        ])));
        $sameComplete = $this->inTenant($fixture, fn () => $builder->build(collect([
            $fixture['agency_a'], $fixture['agency_b'],
        ])));
        $this->assertTrue($complete->complete());
        $this->assertSame('91.250', $complete->matrix[$fixture['agency_a']->id][$fixture['agency_b']->id]);
        $this->assertSame('96.750', $complete->matrix[$fixture['agency_b']->id][$fixture['agency_a']->id]);
        $this->assertSame($complete->fingerprint, $sameComplete->fingerprint);

        $reverse->update(['active' => false]);
        $inactive = $this->inTenant($fixture, fn () => $builder->build(collect([
            $fixture['agency_a'], $fixture['agency_b'],
        ])));
        $this->assertSame('incomplete', $inactive->status);
        $this->assertCount(1, $inactive->missingPairs);
        $this->assertTrue($forward->active);
    }

    public function test_readiness_reports_real_gaps_then_becomes_ready_without_run_job_or_business_mutation(): void
    {
        $fixture = $this->fixture();
        $readiness = app(FleetReallocationReadiness::class);
        $tracked = ['vehicles', 'reservations', 'vehicle_blocks', 'fleet_reallocation_runs', 'jobs'];
        $before = collect($tracked)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        );

        $initial = $this->inTenant($fixture, fn () => $readiness->evaluate(collect([
            $fixture['agency_a'], $fixture['agency_b'],
        ])));
        $this->assertSame('missing_distances', $initial->status);
        $this->assertContains('missing_distances', $initial->issues);
        $this->assertContains('missing_forecasts', $initial->issues);

        $this->distance($fixture, $fixture['agency_a'], $fixture['agency_b']);
        $this->distance($fixture, $fixture['agency_b'], $fixture['agency_a']);
        $this->createSuccessfulForecast($fixture, $fixture['agency_a'], '2026-08-30');
        $missing = $this->inTenant($fixture, fn () => $readiness->evaluate(collect([
            $fixture['agency_a'], $fixture['agency_b'],
        ])));
        $this->assertSame('missing_forecasts', $missing->status);
        $this->assertSame([$fixture['agency_b']->id], $missing->missingForecastAgencyIds);

        $this->createSuccessfulForecast($fixture, $fixture['agency_b'], '2026-08-30');
        $ready = $this->inTenant($fixture, fn () => $readiness->evaluate(collect([
            $fixture['agency_a'], $fixture['agency_b'],
        ])));
        $this->assertTrue($ready->ready());
        $this->assertSame([], $ready->issues);
        $this->assertSame(range(1, 7), array_keys($ready->availabilityByAgencyAndHorizon[$fixture['agency_a']->id]));

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_readiness_rejects_forecasts_with_different_as_of_dates(): void
    {
        $fixture = $this->fixture();
        $this->distance($fixture, $fixture['agency_a'], $fixture['agency_b']);
        $this->distance($fixture, $fixture['agency_b'], $fixture['agency_a']);
        $this->createSuccessfulForecast($fixture, $fixture['agency_a'], '2026-08-30');
        $this->createSuccessfulForecast($fixture, $fixture['agency_b'], '2026-08-29');

        $result = $this->inTenant($fixture, fn () => app(FleetReallocationReadiness::class)
            ->evaluate(collect([$fixture['agency_a'], $fixture['agency_b']])));

        $this->assertSame('incompatible_forecasts', $result->status);
        $this->assertContains('incompatible_forecasts', $result->issues);
    }

    public function test_screen_lists_missing_pairs_and_keeps_the_synthetic_demo_separate(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])
            ->get(route('agency-distances.index'))
            ->assertOk()
            ->assertSee('Distances inter-agences')
            ->assertSee($fixture['agency_a']->name)
            ->assertSee($fixture['agency_b']->name)
            ->assertSee('Départs prévus')
            ->assertSee('Besoin de planification arrondi à l’unité supérieure')
            ->assertDontSee('OR-Tools')
            ->assertDontSee('solveur')
            ->assertDontSee('SYNTH-NODE')
            ->assertDontSee('centimes/km');

        $this->actingAs($fixture['owner'])
            ->get(route('intelligence.fleet-reallocation.index'))
            ->assertOk()
            ->assertSee('Distances inter-agences')
            ->assertSee('Aucun véhicule n’est déplacé automatiquement');
        $this->assertTrue((bool) config('intelligence.fleet_reallocation.synthetic_demo_only'));
        $this->assertDatabaseCount('fleet_reallocation_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_routes_are_guarded_and_migration_count_is_current(): void
    {
        foreach ([
            'agency-distances.index',
            'agency-distances.store',
            'agency-distances.update',
            'agency-distances.activate',
            'agency-distances.deactivate',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware);
            $this->assertContains('tenant', $middleware);
            $this->assertContains('password.changed', $middleware);
        }
        $this->assertContains('throttle:30,1', app('router')->getRoutes()
            ->getByName('agency-distances.store')->gatherMiddleware());
        $this->assertSame(92, DB::table('migrations')->count());
    }

    /** @return array{tenant:Tenant,agency_a:Agency,agency_b:Agency,owner:User} */
    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['settings' => ['timezone' => 'Africa/Casablanca']]);
        [$agencyA, $agencyB] = app(TenantContext::class)->run($tenant, fn (): array => [
            Agency::factory()->create(['name' => 'Casablanca Test']),
            Agency::factory()->create(['name' => 'Rabat Test']),
        ]);
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);

        return ['tenant' => $tenant, 'agency_a' => $agencyA, 'agency_b' => $agencyB, 'owner' => $owner];
    }

    private function user(array $fixture, string $role, ?Agency $agency, bool $mustChange = false): User
    {
        return User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'must_change_password' => $mustChange,
        ]);
    }

    private function payload(array $fixture, array $overrides = []): array
    {
        return [
            'from_agency_id' => $fixture['agency_a']->id,
            'to_agency_id' => $fixture['agency_b']->id,
            'distance_km' => '91.250',
            'source_type' => 'manual_verified',
            'source_reference' => 'Référence de test',
            'same_distance_both_ways' => '0',
            ...$overrides,
        ];
    }

    private function distance(
        array $fixture,
        Agency $from,
        Agency $to,
        string $value = '91.250',
    ): AgencyDistance {
        return $this->inTenant($fixture, fn () => AgencyDistance::query()->create([
            'from_agency_id' => $from->id,
            'to_agency_id' => $to->id,
            'distance_km' => $value,
            'source_type' => AgencyDistanceSourceType::ManualVerified,
            'source_reference' => 'Référence de test',
            'verified_by_user_id' => $fixture['owner']->id,
            'verified_at' => now(),
            'active' => true,
        ]));
    }

    private function row(array $fixture, Agency $from, Agency $to, string $value): array
    {
        return [
            'tenant_id' => $fixture['tenant']->id,
            'from_agency_id' => $from->id,
            'to_agency_id' => $to->id,
            'distance_km' => $value,
            'source_type' => 'manual_verified',
            'source_reference' => null,
            'verified_by_user_id' => $fixture['owner']->id,
            'verified_at' => now(),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback);
    }

    private function createSuccessfulForecast(array $fixture, Agency $agency, string $asOfDate): void
    {
        DB::transaction(function () use ($agency, $asOfDate, $fixture): void {
            $historyUuid = (string) Str::uuid();
            $digest = hash('sha256', $historyUuid);
            $historyId = DB::table('demand_history_export_runs')->insertGetId([
                'tenant_id' => $fixture['tenant']->id,
                'agency_id' => $agency->id,
                'run_id' => $historyUuid,
                'manifest_version' => DemandForecastContract::MANIFEST_VERSION,
                'schema_version' => DemandForecastContract::DATASET_SCHEMA_VERSION,
                'dataset_version' => DemandForecastContract::DATASET_VERSION,
                'preprocessing_version' => DemandForecastContract::PREPROCESSING_VERSION,
                'target_semantics' => DemandForecastContract::TARGET,
                'vehicle_category_scope' => DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
                'timezone' => DemandForecastContract::TIMEZONE,
                'distance_unit' => DemandForecastContract::DISTANCE_UNIT,
                'agency_key' => 'a_'.str_repeat('a', 64),
                'series_key' => 's_'.str_repeat('b', 64),
                'date_from' => CarbonImmutable::parse($asOfDate)->subDays(34)->toDateString(),
                'date_to' => $asOfDate,
                'row_count' => 35,
                'max_rows' => 731,
                'observed_departures_count' => 10,
                'content_sha256' => $digest,
                'byte_size' => 1,
                'format' => 'csv',
                'stored_path' => 'intelligence/demand-history/'.$historyUuid.'.csv',
                'original_name' => 'rentfleet_demand_history_'.$historyUuid.'.csv',
                'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                'created_by' => $fixture['owner']->id,
                'created_at' => now(),
            ]);

            $forecastUuid = (string) Str::uuid();
            $forecastId = DB::table('demand_forecast_runs')->insertGetId([
                'tenant_id' => $fixture['tenant']->id,
                'agency_id' => $agency->id,
                'demand_history_export_run_id' => $historyId,
                'run_id' => $forecastUuid,
                'idempotency_key' => (string) Str::uuid(),
                'schema_version' => DemandForecastContract::RESULT_SCHEMA_VERSION,
                'model_name' => DemandForecastContract::MODEL_NAME,
                'model_version' => DemandForecastContract::MODEL_VERSION,
                'model_artifact_sha256' => DemandForecastContract::MODEL_ARTIFACT_SHA256,
                'framework' => DemandForecastContract::FRAMEWORK,
                'framework_version' => DemandForecastContract::FRAMEWORK_VERSION,
                'compute' => 'cpu',
                'explanation_method' => DemandForecastContract::EXPLANATION_METHOD,
                'mode' => 'consultative_shadow',
                'validation_scope' => 'public_proxy_only_local_shadow',
                'target_semantics' => DemandForecastContract::TARGET,
                'generated_at' => now(),
                'as_of_date' => $asOfDate,
                'input_row_count' => 35,
                'input_content_sha256' => $digest,
                'result_count' => 7,
                'public_wape' => DemandForecastContract::PUBLIC_WAPE,
                'public_mase' => DemandForecastContract::PUBLIC_MASE,
                'public_interval_coverage' => DemandForecastContract::PUBLIC_INTERVAL_COVERAGE,
                'local_holdout_status' => 'not_available_pending_real_history',
                'local_wape' => null,
                'local_mase' => null,
                'local_interval_coverage' => null,
                'canonical_payload_sha256' => hash('sha256', $forecastUuid.'canonical'),
                'content_sha256' => hash('sha256', $forecastUuid.'content'),
                'byte_size' => 1,
                'stored_path' => 'intelligence/demand-forecasts/'.$forecastUuid.'.json',
                'original_name' => 'rentfleet_demand_forecast_'.$forecastUuid.'.json',
                'validation_status' => 'validated',
                'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                'imported_by' => $fixture['owner']->id,
                'imported_at' => now(),
            ]);

            foreach (range(1, 7) as $horizon) {
                DB::table('demand_forecasts')->insert([
                    'tenant_id' => $fixture['tenant']->id,
                    'agency_id' => $agency->id,
                    'demand_forecast_run_id' => $forecastId,
                    'row_position' => $horizon - 1,
                    'target_date' => CarbonImmutable::parse($asOfDate)->addDays($horizon)->toDateString(),
                    'horizon' => $horizon,
                    'vehicle_category_scope' => DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
                    'conditional_mean' => '4.250000',
                    'p05' => '2.000000',
                    'p50' => '4.000000',
                    'p90' => '6.000000',
                    'p95' => '7.000000',
                    'raw_any_crossing' => false,
                    'monotone_adjusted' => false,
                    'explanations' => json_encode([[], [], []], JSON_THROW_ON_ERROR),
                    'demand_semantics' => DemandForecastContract::TARGET,
                    'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                    'created_at' => now(),
                ]);
            }

            DB::table('demand_forecast_execution_runs')->insert([
                'tenant_id' => $fixture['tenant']->id,
                'agency_id' => $agency->id,
                'run_id' => (string) Str::uuid(),
                'demand_history_export_run_id' => $historyId,
                'requested_by' => $fixture['owner']->id,
                'demand_forecast_run_id' => $forecastId,
                'status' => 'succeeded',
                'failure_code' => null,
                'model_artifact_sha256' => DemandForecastContract::MODEL_ARTIFACT_SHA256,
                'model_artifact_bytes' => DemandForecastContract::MODEL_ARTIFACT_BYTES,
                'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                'requested_at' => now()->subMinute(),
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
            ]);
        });
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Une contrainte PostgreSQL devait refuser cette écriture.');
        } catch (QueryException $exception) {
            $this->assertSame($state, (string) $exception->getCode());
        }
    }
}
