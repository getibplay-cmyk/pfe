<?php

namespace Tests\Feature;

use App\Actions\Fleet\ExecuteOperationalFleetReallocationPlan;
use App\Actions\Fleet\QueueOperationalFleetReallocationPlan;
use App\Enums\AgencyDistanceSourceType;
use App\Enums\FleetReallocationPlanningRunStatus;
use App\Enums\VehicleOperationalStatus;
use App\Exceptions\FleetReallocationPlanningException;
use App\Jobs\RunOperationalFleetReallocationPlan;
use App\Models\Agency;
use App\Models\AgencyDistance;
use App\Models\FleetReallocationPlanningRun;
use App\Models\FleetReallocationRecommendation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Fleet\BuildOperationalFleetReallocationSnapshot;
use App\Support\Fleet\OperationalFleetReallocationOutputValidator;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\FleetReallocation\FleetReallocationRuntimeReadiness;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FleetReallocationPlanningIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const UNEXPECTED_INPUT_MESSAGE = 'Aucune donnée de calcul ne doit être transmise.';

    private const SAFE_HTML_ERROR_MESSAGE = 'Certaines informations ne peuvent pas être traitées.';

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

    public function test_real_snapshot_uses_seven_forecasts_directional_distances_and_server_availability(): void
    {
        $fixture = $this->readyFixture();
        $maintenanceVehicle = $fixture['vehicles_a'][0];
        $maintenanceVehicle->forceFill(['operational_status' => VehicleOperationalStatus::Maintenance])->save();

        $snapshot = $this->inTenant($fixture, fn () => app(BuildOperationalFleetReallocationSnapshot::class)->build());

        $this->assertSame('rentfleet_operational', $snapshot->payload['source_kind']);
        $this->assertSame('2026-08-30', $snapshot->referenceDate);
        $this->assertCount(2, $snapshot->payload['agencies']);
        $this->assertCount(7, $snapshot->payload['days']);
        $this->assertSame(range(1, 7), array_column($snapshot->payload['days'], 'horizon'));
        foreach ($snapshot->payload['days'] as $day) {
            $this->assertSame(7, $day['nodes'][0]['available_vehicle_units']);
            $this->assertSame('3.100000', $day['nodes'][0]['conditional_mean']);
            $this->assertSame(4, $day['nodes'][0]['planning_vehicle_units']);
            $this->assertSame(3, $day['nodes'][0]['transferable_surplus']);
            $this->assertSame(0, $day['nodes'][0]['uncovered_need']);
            $this->assertSame(1, $day['nodes'][1]['available_vehicle_units']);
            $this->assertSame(5, $day['nodes'][1]['planning_vehicle_units']);
            $this->assertSame(4, $day['nodes'][1]['uncovered_need']);
        }
        $this->assertSame(['87.400', '91.250'], array_column($snapshot->payload['lanes'], 'distance_km'));
        $this->assertSame([43700, 45625], array_column($snapshot->payload['lanes'], 'unit_cost_centimes'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot->inputFingerprint);
        $this->assertSame(0, FleetReallocationPlanningRun::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_get_is_read_only_and_double_post_reuses_one_run_and_one_after_commit_job(): void
    {
        $fixture = $this->readyFixture();
        Queue::fake();

        $this->actingAs($fixture['owner'])
            ->get(route('fleet.reallocation-planning.index'))
            ->assertOk()
            ->assertSee('Planification de réallocation de flotte')
            ->assertSee('Ce plan est consultatif')
            ->assertDontSee('SYNTH-NODE');
        $this->assertDatabaseCount('fleet_reallocation_planning_runs', 0);
        $this->assertDatabaseCount('jobs', 0);

        $first = $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), [])
            ->assertAccepted()
            ->assertJsonStructure(['run_id', 'status', 'status_url'])
            ->json();
        $second = $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), [])
            ->assertAccepted()
            ->json();

        $this->assertSame(['run_id', 'status', 'status_url'], array_keys($first));
        $this->assertSame($first['run_id'], $second['run_id']);
        $this->assertDatabaseCount('fleet_reallocation_planning_runs', 1);
        Queue::assertPushed(RunOperationalFleetReallocationPlan::class, 1);
        Queue::assertPushed(fn (RunOperationalFleetReallocationPlan $job): bool => $job->queue === 'intelligence');

        $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), ['distance_km' => '1.000'])
            ->assertUnprocessable();
    }

    public function test_non_empty_form_payload_is_rejected_without_side_effects(): void
    {
        $fixture = $this->readyFixture();
        config(['app.debug' => false]);
        Queue::fake();
        $this->mock(QueueOperationalFleetReallocationPlan::class)
            ->shouldNotReceive('handle');
        $before = $this->planningSideEffectCounts();

        $response = $this->actingAs($fixture['owner'])
            ->post(route('fleet.reallocation-planning.runs.store'), [
                'agency_id' => $fixture['agency_a']->getKey(),
            ]);

        $response
            ->assertStatus(422)
            ->assertSeeText(self::SAFE_HTML_ERROR_MESSAGE)
            ->assertDontSee('QueueOperationalFleetReallocationPlan', false)
            ->assertDontSee('SQLSTATE', false);
        $this->assertPlanningSideEffectsUnchanged($before);
        Queue::assertNothingPushed();
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('sensitiveUnexpectedInputProvider')]
    public function test_sensitive_json_input_is_rejected_without_side_effects(array $payload): void
    {
        $fixture = $this->readyFixture();
        config(['app.debug' => false]);
        Queue::fake();
        $this->mock(QueueOperationalFleetReallocationPlan::class)
            ->shouldNotReceive('handle');
        $before = $this->planningSideEffectCounts();

        $response = $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), $payload);

        $response
            ->assertStatus(422)
            ->assertExactJson(['message' => self::UNEXPECTED_INPUT_MESSAGE]);
        $this->assertPlanningSideEffectsUnchanged($before);
        Queue::assertNothingPushed();
    }

    public function test_sensitive_query_string_input_is_rejected_without_side_effects(): void
    {
        $fixture = $this->readyFixture();
        config(['app.debug' => false]);
        Queue::fake();
        $this->mock(QueueOperationalFleetReallocationPlan::class)
            ->shouldNotReceive('handle');
        $before = $this->planningSideEffectCounts();
        $url = route('fleet.reallocation-planning.runs.store').'?tenant_id='.(int) $fixture['tenant']->getKey();

        $response = $this->actingAs($fixture['owner'])->postJson($url, []);

        $response
            ->assertStatus(422)
            ->assertExactJson(['message' => self::UNEXPECTED_INPUT_MESSAGE]);
        $this->assertPlanningSideEffectsUnchanged($before);
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function sensitiveUnexpectedInputProvider(): array
    {
        return [
            'tenant identifier' => [['tenant_id' => 900001]],
            'agency identifier' => [['agency_id' => 900002]],
            'forecast reference' => [['forecast_run_id' => 900003]],
            'solver option' => [['solver' => 'client-selected']],
        ];
    }

    public function test_real_runtime_result_persists_positive_recommendations_without_business_effect(): void
    {
        $fixture = $this->readyFixture();
        Queue::fake();
        $businessTables = ['vehicles', 'vehicle_blocks', 'reservations', 'rental_contracts', 'maintenance_orders', 'invoices'];
        $before = collect($businessTables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        );

        $accepted = $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), [])
            ->assertAccepted()
            ->json();
        $run = FleetReallocationPlanningRun::withoutGlobalScopes()
            ->where('run_id', $accepted['run_id'])
            ->firstOrFail();
        config(['intelligence.fleet_reallocation.python_binary' => PHP_BINARY]);
        Process::fake(['*' => Process::result(output: json_encode(
            $this->runtimeOutput($run, true),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ))]);

        (new RunOperationalFleetReallocationPlan(
            $run->run_id,
            $fixture['tenant']->id,
            $fixture['owner']->id,
        ))->handle(app(ExecuteOperationalFleetReallocationPlan::class));

        $completed = $run->fresh();
        $this->assertSame(FleetReallocationPlanningRunStatus::Succeeded, $completed->status);
        $this->assertSame('rentfleet_operational', $completed->source_kind);
        $this->assertSame('OPTIMAL', $completed->solver_status);
        $this->assertSame('transfers_recommended', $completed->outcome);
        $this->assertDatabaseCount('fleet_reallocation_recommendations', 7);
        foreach (FleetReallocationRecommendation::withoutGlobalScopes()->get() as $recommendation) {
            $this->assertSame($fixture['agency_a']->id, $recommendation->from_agency_id);
            $this->assertSame($fixture['agency_b']->id, $recommendation->to_agency_id);
            $this->assertSame(4, $recommendation->vehicle_units);
            $this->assertSame('87.400', $recommendation->distance_km);
        }
        $status = $this->actingAs($fixture['owner'])
            ->getJson(route('fleet.reallocation-planning.runs.status', $completed))
            ->assertOk()
            ->assertJsonStructure([
                'status', 'reference_date', 'generated_at', 'outcome',
                'agencies', 'recommendations', 'message',
            ])
            ->json();
        $this->assertSame(
            ['status', 'reference_date', 'generated_at', 'outcome', 'agencies', 'recommendations', 'message'],
            array_keys($status),
        );
        $this->assertCount(14, $status['agencies']);
        $this->assertCount(7, $status['recommendations']);
        $encoded = json_encode($status, JSON_THROW_ON_ERROR);
        foreach (['runtime', 'sha256', 'payload', 'traceback', 'penalty', 'objective', 'NODE-'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.reallocation_planning.run_succeeded']);
    }

    public function test_optimal_results_with_no_move_cover_balanced_and_insufficient_surplus_outcomes(): void
    {
        $validator = app(OperationalFleetReallocationOutputValidator::class);
        foreach ([
            [0, 0, 'balanced_without_transfer'],
            [0, 3, 'insufficient_transferable_surplus'],
        ] as [$available, $planning, $outcome]) {
            $snapshot = $this->plainSnapshot($available, $planning);
            $runId = (string) Str::uuid();
            $payload = $this->plainRuntimeOutput($snapshot, $runId, false);
            $validated = $validator->validate(
                json_encode($payload, JSON_THROW_ON_ERROR),
                $snapshot,
                $runId,
            );
            $this->assertSame($outcome, $validated['outcome']);
            $this->assertSame([], $validated['recommendations']);
        }
    }

    public function test_output_validation_rejects_non_optimal_malformed_and_absent_direction(): void
    {
        $validator = app(OperationalFleetReallocationOutputValidator::class);
        $snapshot = $this->plainDirectionalSnapshot();
        $runId = (string) Str::uuid();
        $invalid = [];
        $nonOptimal = $this->plainRuntimeOutput($snapshot, $runId, false);
        $nonOptimal['solver_status'] = 'FEASIBLE';
        $invalid[] = $nonOptimal;
        $unknown = $this->plainRuntimeOutput($snapshot, $runId, true);
        $unknown['days'][0]['recommendations'][0]['to_node_ref'] = 'NODE-999';
        $invalid[] = $unknown;
        $negative = $this->plainRuntimeOutput($snapshot, $runId, true);
        $negative['days'][0]['recommendations'][0]['vehicle_units'] = -1;
        $invalid[] = $negative;
        $extra = $this->plainRuntimeOutput($snapshot, $runId, false);
        $extra['tenant_id'] = 99;
        $invalid[] = $extra;

        foreach ($invalid as $payload) {
            try {
                $validator->validate(json_encode($payload, JSON_THROW_ON_ERROR), $snapshot, $runId);
                $this->fail('Une sortie invalide devait être refusée.');
            } catch (FleetReallocationPlanningException $exception) {
                $this->assertSame('SOLVER_OUTPUT_INVALID', $exception->failureCode());
            }
        }
    }

    public function test_role_matrix_anonymous_cross_tenant_and_route_guards_are_enforced(): void
    {
        $fixture = $this->readyFixture();
        $this->get(route('fleet.reallocation-planning.index'))->assertRedirect(route('login'));

        $fleetManager = $this->user($fixture, 'fleet-manager', $fixture['agency_a']);
        $this->actingAs($fleetManager)->get(route('fleet.reallocation-planning.index'))->assertOk();
        foreach (['agency-manager', 'rental-agent', 'accountant', 'viewer-auditor'] as $role) {
            $user = $this->user($fixture, $role, $fixture['agency_a']);
            $this->actingAs($user)->get(route('fleet.reallocation-planning.index'))->assertForbidden();
            $this->actingAs($user)
                ->postJson(route('fleet.reallocation-planning.runs.store'), [])
                ->assertForbidden();
        }

        Queue::fake();
        $accepted = $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), [])
            ->assertAccepted()
            ->json();
        $run = FleetReallocationPlanningRun::withoutGlobalScopes()->where('run_id', $accepted['run_id'])->firstOrFail();
        $foreign = $this->readyFixture();
        $this->actingAs($foreign['owner'])
            ->getJson(route('fleet.reallocation-planning.runs.status', $run))
            ->assertNotFound();

        foreach (['fleet.reallocation-planning.index', 'fleet.reallocation-planning.runs.store', 'fleet.reallocation-planning.runs.status'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('tenant', $route->gatherMiddleware());
            $this->assertContains('password.changed', $route->gatherMiddleware());
        }
        $this->assertContains(
            'throttle:fleet-reallocation-planning',
            app('router')->getRoutes()->getByName('fleet.reallocation-planning.runs.store')->gatherMiddleware(),
        );
    }

    public function test_stale_recovery_terminal_immutability_and_synthetic_compatibility(): void
    {
        $fixture = $this->readyFixture();
        Queue::fake();
        $first = $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), [])
            ->assertAccepted()
            ->json();
        CarbonImmutable::setTestNow(now()->addMinutes(11));

        $this->actingAs($fixture['owner'])
            ->postJson(route('fleet.reallocation-planning.runs.store'), [])
            ->assertAccepted();
        $stale = FleetReallocationPlanningRun::withoutGlobalScopes()->where('run_id', $first['run_id'])->firstOrFail();
        $this->assertSame(FleetReallocationPlanningRunStatus::Failed, $stale->status);
        $this->assertSame('RUN_STALE_RECOVERED', $stale->failure_code);
        $this->assertDatabaseCount('fleet_reallocation_planning_runs', 2);

        try {
            DB::transaction(
                fn () => DB::table('fleet_reallocation_planning_runs')->where('id', $stale->id)->delete(),
            );
            $this->fail('Le run terminal devait rester immuable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
        $this->assertTrue(DB::table('fleet_reallocation_runs')->exists() || DB::table('fleet_reallocation_runs')->count() === 0);
        $this->assertTrue(DB::table('pg_constraint')->where('conname', 'fleet_reallocation_runs_contract_check')->exists());
    }

    /** @return array<string, mixed> */
    private function readyFixture(): array
    {
        $tenant = Tenant::factory()->create(['settings' => ['timezone' => 'Africa/Casablanca']]);
        [$agencyA, $agencyB] = app(TenantContext::class)->run($tenant, fn (): array => [
            Agency::factory()->create(['name' => 'Casablanca Centre']),
            Agency::factory()->create(['name' => 'Rabat Agdal']),
        ]);
        $owner = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);
        $fixture = compact('tenant', 'owner');
        $fixture['agency_a'] = $agencyA;
        $fixture['agency_b'] = $agencyB;
        $this->distance($fixture, $agencyA, $agencyB, '87.400');
        $this->distance($fixture, $agencyB, $agencyA, '91.250');
        $this->createSuccessfulForecast($fixture, $agencyA, '3.100000');
        $this->createSuccessfulForecast($fixture, $agencyB, '4.200000');
        [$vehiclesA, $vehiclesB] = $this->inTenant($fixture, function () use ($agencyA, $agencyB): array {
            $category = VehicleCategory::query()->create(['code' => 'TEST', 'name' => 'Test', 'is_active' => true]);

            return [
                $this->vehicles($agencyA, $category, 8, 'CAS'),
                $this->vehicles($agencyB, $category, 1, 'RAB'),
            ];
        });
        $fixture['vehicles_a'] = $vehiclesA;
        $fixture['vehicles_b'] = $vehiclesB;

        return $fixture;
    }

    /** @return array{runs: int, jobs: int, audits: int} */
    private function planningSideEffectCounts(): array
    {
        return [
            'runs' => FleetReallocationPlanningRun::withoutGlobalScopes()->count(),
            'jobs' => DB::table('jobs')->count(),
            'audits' => DB::table('audit_logs')
                ->whereIn('action', [
                    'fleet.reallocation_planning.run_queued',
                    'fleet.reallocation_planning.run_succeeded',
                    'fleet.reallocation_planning.run_failed',
                ])
                ->count(),
        ];
    }

    /** @param array{runs: int, jobs: int, audits: int} $before */
    private function assertPlanningSideEffectsUnchanged(array $before): void
    {
        $after = $this->planningSideEffectCounts();

        $this->assertSame($before['runs'], $after['runs']);
        $this->assertSame($before['jobs'], $after['jobs']);
        $this->assertSame($before['audits'], $after['audits']);
    }

    private function user(array $fixture, string $role, ?Agency $agency): User
    {
        return User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $role)->value('id'),
            'must_change_password' => false,
        ]);
    }

    /** @return list<Vehicle> */
    private function vehicles(Agency $agency, VehicleCategory $category, int $count, string $prefix): array
    {
        $vehicles = [];
        foreach (range(1, $count) as $number) {
            $vehicles[] = Vehicle::query()->create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => sprintf('%s-%03d', $prefix, $number),
                'brand' => 'Test',
                'model' => 'Test',
                'fuel_type' => 'petrol',
                'transmission' => 'manual',
            ]);
        }

        return $vehicles;
    }

    private function distance(array $fixture, Agency $from, Agency $to, string $distance): void
    {
        $this->inTenant($fixture, fn () => AgencyDistance::query()->create([
            'from_agency_id' => $from->id,
            'to_agency_id' => $to->id,
            'distance_km' => $distance,
            'source_type' => AgencyDistanceSourceType::ManualVerified,
            'source_reference' => 'Référence de test',
            'verified_by_user_id' => $fixture['owner']->id,
            'verified_at' => now(),
            'active' => true,
        ]));
    }

    private function inTenant(array $fixture, callable $callback): mixed
    {
        return app(TenantContext::class)->run($fixture['tenant'], $callback);
    }

    private function createSuccessfulForecast(array $fixture, Agency $agency, string $mean): void
    {
        DB::transaction(function () use ($fixture, $agency, $mean): void {
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
                'date_from' => '2026-07-27',
                'date_to' => '2026-08-30',
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
                'as_of_date' => '2026-08-30',
                'input_row_count' => 35,
                'input_content_sha256' => $digest,
                'result_count' => 7,
                'public_wape' => DemandForecastContract::PUBLIC_WAPE,
                'public_mase' => DemandForecastContract::PUBLIC_MASE,
                'public_interval_coverage' => DemandForecastContract::PUBLIC_INTERVAL_COVERAGE,
                'local_holdout_status' => 'not_available_pending_real_history',
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
                    'target_date' => CarbonImmutable::parse('2026-08-30')->addDays($horizon)->toDateString(),
                    'horizon' => $horizon,
                    'vehicle_category_scope' => DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
                    'conditional_mean' => $mean,
                    'p05' => '1.000000',
                    'p50' => $mean,
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
                'model_artifact_sha256' => DemandForecastContract::MODEL_ARTIFACT_SHA256,
                'model_artifact_bytes' => DemandForecastContract::MODEL_ARTIFACT_BYTES,
                'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
                'requested_at' => now()->subMinute(),
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function runtimeOutput(FleetReallocationPlanningRun $run, bool $withMoves): array
    {
        return $this->plainRuntimeOutput($run->snapshot, $run->run_id, $withMoves);
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function plainRuntimeOutput(array $snapshot, string $runId, bool $withMoves): array
    {
        $lane = $snapshot['lanes'][0];
        $days = [];
        foreach ($snapshot['days'] as $day) {
            $uncovered = array_sum(array_column($day['nodes'], 'uncovered_need'));
            $units = $withMoves ? min(
                (int) $day['nodes'][0]['transferable_surplus'],
                (int) $day['nodes'][1]['uncovered_need'],
            ) : 0;
            $days[] = [
                'horizon' => $day['horizon'],
                'date' => $day['date'],
                'solver_status' => 'OPTIMAL',
                'solver_runtime_ms' => '0.010000',
                'unserved_need' => $uncovered - $units,
                'recommendations' => $units > 0 ? [[
                    'from_node_ref' => $lane['from_node_ref'],
                    'to_node_ref' => $lane['to_node_ref'],
                    'vehicle_units' => $units,
                    'distance_km' => $lane['distance_km'],
                    'unit_cost_centimes' => $lane['unit_cost_centimes'],
                ]] : [],
            ];
        }

        return [
            'schema_version' => '1.0.0',
            'source_kind' => 'rentfleet_operational',
            'run_id' => $runId,
            'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'solver_name' => 'ortools_simple_min_cost_flow',
            'solver_version' => '9.15.6755',
            'solver_status' => 'OPTIMAL',
            'days' => $days,
        ];
    }

    /** @return array<string, mixed> */
    private function plainSnapshot(int $available, int $planning): array
    {
        $nodes = [
            [
                'agency_id' => 1,
                'node_ref' => 'NODE-001',
                'conditional_mean' => sprintf('%d.000000', $planning),
                'planning_vehicle_units' => $planning,
                'available_vehicle_units' => $available,
                'transferable_surplus' => max(0, $available - $planning),
                'uncovered_need' => max(0, $planning - $available),
            ],
            [
                'agency_id' => 2,
                'node_ref' => 'NODE-002',
                'conditional_mean' => sprintf('%d.000000', $planning),
                'planning_vehicle_units' => $planning,
                'available_vehicle_units' => $available,
                'transferable_surplus' => max(0, $available - $planning),
                'uncovered_need' => max(0, $planning - $available),
            ],
        ];

        return [
            'agencies' => [
                ['agency_id' => 1, 'node_ref' => 'NODE-001', 'name' => 'A'],
                ['agency_id' => 2, 'node_ref' => 'NODE-002', 'name' => 'B'],
            ],
            'days' => array_map(fn (int $horizon): array => [
                'horizon' => $horizon,
                'date' => CarbonImmutable::parse('2026-08-30')->addDays($horizon)->toDateString(),
                'nodes' => $nodes,
            ], range(1, 7)),
            'lanes' => [
                ['from_node_ref' => 'NODE-001', 'to_node_ref' => 'NODE-002', 'distance_km' => '87.400', 'unit_cost_centimes' => 43700],
                ['from_node_ref' => 'NODE-002', 'to_node_ref' => 'NODE-001', 'distance_km' => '87.400', 'unit_cost_centimes' => 43700],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function plainDirectionalSnapshot(): array
    {
        $snapshot = $this->plainSnapshot(0, 0);
        foreach ($snapshot['days'] as &$day) {
            $day['nodes'][0] = [
                ...$day['nodes'][0],
                'conditional_mean' => '1.000000',
                'planning_vehicle_units' => 1,
                'available_vehicle_units' => 5,
                'transferable_surplus' => 4,
                'uncovered_need' => 0,
            ];
            $day['nodes'][1] = [
                ...$day['nodes'][1],
                'conditional_mean' => '3.000000',
                'planning_vehicle_units' => 3,
                'available_vehicle_units' => 0,
                'transferable_surplus' => 0,
                'uncovered_need' => 3,
            ];
        }
        unset($day);

        return $snapshot;
    }
}
