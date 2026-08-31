<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteDemandForecastExecution;
use App\Jobs\RunDemandForecast;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\DemandForecast;
use App\Models\DemandForecastExecutionRun;
use App\Models\DemandForecastRun;
use App\Models\DemandHistoryExportRun;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Intelligence\DemandForecasting\DemandForecastCanonicalPayload;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastModelArtifact;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationDemandForecastAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-30 10:00:00+01:00');
        config(['intelligence.export_hmac_key' => str_repeat('planning-test-key-', 4)]);
        config(['intelligence.demand_forecasting.runtime_enabled' => true]);
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reservation_page_shows_empty_consultative_card_without_creating_a_run(): void
    {
        $fixture = $this->fixture();
        $before = $this->intelligenceCounts();
        $this->fakeRuntime();

        $this->actingAs($fixture['user'])
            ->get(route('reservations.index', ['agency_id' => $fixture['agency']->id]))
            ->assertOk()
            ->assertSee('Prévision de la demande — 7 prochains jours')
            ->assertSee('Aucune prévision récente n’est disponible pour cette agence.')
            ->assertSee('Actualiser les prévisions')
            ->assertSee('Elles ne modifient aucune réservation')
            ->assertDontSee('HistGradientBoosting')
            ->assertDontSee('joblib')
            ->assertDontSee('SHA-256')
            ->assertDontSee('runtime')
            ->assertDontSee('worker');

        $this->assertSame($before, $this->intelligenceCounts());
    }

    public function test_role_matrix_controls_card_visibility_and_refresh_action(): void
    {
        $fixture = $this->fixture();
        $this->fakeRuntime(false);

        foreach ([
            'tenant-owner' => [true, true],
            'agency-manager' => [true, false],
            'rental-agent' => [false, false],
            'fleet-manager' => [true, true],
            'viewer-auditor' => [true, false],
            'accountant' => [false, false],
        ] as $role => [$cardVisible, $refreshVisible]) {
            $user = $role === 'tenant-owner'
                ? $fixture['user']
                : $this->user($fixture, $role, $fixture['agency']);
            $response = $this->actingAs($user)->get(route('reservations.index'));
            $response->assertOk();
            $cardVisible
                ? $response->assertSee('Prévision de la demande — 7 prochains jours')
                : $response->assertDontSee('Prévision de la demande — 7 prochains jours');
            $refreshVisible
                ? $response->assertSee('Actualiser les prévisions')
                : $response->assertDontSee('Actualiser les prévisions');
        }
    }

    public function test_post_returns_exact_accepted_contract_and_reuses_the_active_scope(): void
    {
        $fixture = $this->fixtureWithDeparture();
        $this->fakeRuntime();
        Queue::fake();

        $first = $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertAccepted();
        $execution = DemandForecastExecutionRun::withoutGlobalScopes()->sole();
        $expected = [
            'run_id' => $execution->run_id,
            'status' => 'queued',
            'status_url' => route('reservations.demand-forecast.show', $execution),
        ];
        $first->assertExactJson($expected);

        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertAccepted()->assertExactJson($expected);

        $this->assertSame(1, DemandHistoryExportRun::withoutGlobalScopes()->count());
        $this->assertSame(1, DemandForecastExecutionRun::withoutGlobalScopes()->count());
        Queue::assertPushed(RunDemandForecast::class, 1);
    }

    public function test_status_exposes_only_sanitized_queued_running_and_failed_contracts(): void
    {
        $fixture = $this->fixtureWithDeparture();
        $this->fakeRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertAccepted();
        $run = DemandForecastExecutionRun::withoutGlobalScopes()->sole();
        $url = route('reservations.demand-forecast.show', $run);

        $this->actingAs($fixture['user'])->getJson($url)->assertExactJson([
            'status' => 'queued',
            'generated_at' => null,
            'scope' => ['agency' => $fixture['agency']->name],
            'forecasts' => [],
            'message' => 'Préparation des prévisions en cours…',
        ]);

        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
        $this->actingAs($fixture['user'])->getJson($url)->assertExactJson([
            'status' => 'running',
            'generated_at' => null,
            'scope' => ['agency' => $fixture['agency']->name],
            'forecasts' => [],
            'message' => 'Préparation des prévisions en cours…',
        ]);

        $run->forceFill([
            'status' => 'failed',
            'failure_code' => 'HGB_PROCESS_FAILED',
            'finished_at' => now(),
        ])->save();
        $response = $this->actingAs($fixture['user'])->getJson($url)->assertExactJson([
            'status' => 'failed',
            'generated_at' => null,
            'scope' => ['agency' => $fixture['agency']->name],
            'forecasts' => [],
            'message' => 'Les prévisions ne sont pas disponibles. Le planning reste utilisable.',
        ]);
        $response->assertDontSee('HGB_PROCESS_FAILED')
            ->assertDontSee('joblib')
            ->assertDontSee('exception');
    }

    public function test_succeeded_status_and_reservation_page_use_exact_d_plus_one_to_seven_values(): void
    {
        $fixture = $this->fixtureWithDeparture();
        $execution = $this->completeExecution($fixture);
        $expected = collect(range(1, 7))->map(fn (int $horizon): array => [
            'date' => CarbonImmutable::today(DemandForecastContract::TIMEZONE)
                ->addDays($horizon)->toDateString(),
            'predicted_demand' => number_format(10 + $horizon, 6, '.', ''),
        ])->all();

        $response = $this->actingAs($fixture['user'])
            ->getJson(route('reservations.demand-forecast.show', $execution))
            ->assertOk();
        $payload = $response->json();
        $this->assertSame([
            'status',
            'generated_at',
            'scope',
            'forecasts',
            'message',
        ], array_keys($payload));
        $this->assertSame($expected, $payload['forecasts']);
        $this->assertSame($fixture['agency']->name, $payload['scope']['agency']);
        $this->assertNotNull($payload['generated_at']);

        $page = $this->actingAs($fixture['user'])
            ->get(route('reservations.index', ['agency_id' => $fixture['agency']->id]))
            ->assertOk()
            ->assertSee('Les prévisions sont disponibles pour préparer le planning.')
            ->assertSee('Véhicules à prévoir')
            ->assertDontSee('7,2 véhicules')
            ->assertDontSee('7.2 véhicules')
            ->assertDontSee('19,263290 véhicules');
        foreach ($expected as $forecast) {
            $page->assertSee($forecast['date']);
            $page->assertSee($forecast['predicted_demand']);
        }

        $this->assertSame(
            $expected[0]['predicted_demand'],
            (string) DemandForecast::withoutGlobalScopes()->orderBy('horizon')->value('conditional_mean'),
        );
    }

    public function test_succeeded_run_remains_readable_when_status_is_polled_after_midnight(): void
    {
        CarbonImmutable::setTestNow('2026-08-30 23:59:00+01:00');
        $fixture = $this->fixtureWithDeparture();
        $execution = $this->completeExecution($fixture);

        CarbonImmutable::setTestNow('2026-08-31 00:01:00+01:00');

        $this->actingAs($fixture['user'])
            ->getJson(route('reservations.demand-forecast.show', $execution))
            ->assertOk()
            ->assertJsonPath('forecasts.0.date', '2026-08-31')
            ->assertJsonPath('forecasts.6.date', '2026-09-06');
    }

    public function test_disabled_or_unavailable_service_fails_safely_without_creating_anything(): void
    {
        $fixture = $this->fixtureWithDeparture();
        Queue::fake();

        config(['intelligence.demand_forecasting.runtime_enabled' => false]);
        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertServiceUnavailable()->assertExactJson([
            'message' => 'Le service de prévision est momentanément indisponible. Le planning reste utilisable.',
        ]);

        config(['intelligence.demand_forecasting.runtime_enabled' => true]);
        $this->fakeRuntime(false);
        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertServiceUnavailable();

        $this->assertSame(0, DemandHistoryExportRun::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandForecastExecutionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_agency_and_tenant_scope_are_enforced_for_start_and_status(): void
    {
        $fixture = $this->fixtureWithDeparture('fleet-manager');
        config(['intelligence.demand_forecasting.rate_limits.user_per_minute' => 20]);
        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence hors périmètre']),
        );
        $foreign = $this->fixture('tenant-owner', 'Tenant étranger');
        $this->fakeRuntime();
        Queue::fake();

        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $otherAgency->id],
        )->assertForbidden();
        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id, 'tenant_id' => $fixture['tenant']->id],
        )->assertUnprocessable();
        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertAccepted();
        $run = DemandForecastExecutionRun::withoutGlobalScopes()->sole();

        $this->actingAs($foreign['user'])
            ->getJson(route('reservations.demand-forecast.show', $run))
            ->assertNotFound();
    }

    public function test_view_only_and_non_prediction_roles_cannot_start_a_forecast(): void
    {
        $fixture = $this->fixtureWithDeparture();
        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $manager = $this->user($fixture, 'agency-manager', $fixture['agency']);
        $agent = $this->user($fixture, 'rental-agent', $fixture['agency']);
        $accountant = $this->user($fixture, 'accountant', $fixture['agency']);
        $this->fakeRuntime();
        Queue::fake();

        foreach ([$viewer, $manager, $agent, $accountant] as $user) {
            $this->actingAs($user)->postJson(
                route('reservations.demand-forecast.store'),
                ['agency_id' => $fixture['agency']->id],
            )->assertForbidden();
        }

        $this->assertSame(0, DemandForecastExecutionRun::withoutGlobalScopes()->count());
    }

    public function test_two_tenants_and_two_agencies_do_not_share_the_active_lock(): void
    {
        $first = $this->fixtureWithDeparture();
        $second = $this->fixtureWithDeparture('tenant-owner', 'Deuxième tenant');
        $otherAgency = app(TenantContext::class)->run(
            $first['tenant'],
            fn () => Agency::factory()->create(['name' => 'Deuxième agence autorisée']),
        );
        $this->departure($first, $otherAgency, 'B');
        $this->fakeRuntime();
        Queue::fake();

        foreach ([
            [$first['user'], $first['agency']->id],
            [$first['user'], $otherAgency->id],
            [$second['user'], $second['agency']->id],
        ] as [$user, $agencyId]) {
            $this->actingAs($user)->postJson(
                route('reservations.demand-forecast.store'),
                ['agency_id' => $agencyId],
            )->assertAccepted();
        }

        $this->assertSame(3, DemandForecastExecutionRun::withoutGlobalScopes()->count());
        $this->assertSame(3, DemandHistoryExportRun::withoutGlobalScopes()->count());
        Queue::assertPushed(RunDemandForecast::class, 3);
    }

    public function test_rate_limit_routes_and_web_security_are_present(): void
    {
        $fixture = $this->fixtureWithDeparture();
        $this->fakeRuntime();
        Queue::fake();
        config(['intelligence.demand_forecasting.rate_limits.user_per_minute' => 1]);
        config(['intelligence.demand_forecasting.rate_limits.scope_per_hour' => 10]);
        RateLimiter::clear('reservation-demand-forecast');

        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertAccepted();
        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertTooManyRequests();

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->keyBy(fn ($route) => $route->getName());
        foreach (['reservations.demand-forecast.store', 'reservations.demand-forecast.show'] as $name) {
            $route = $routes->get($name);
            $this->assertNotNull($route);
            $declaredMiddleware = $route->gatherMiddleware();
            $resolvedMiddleware = app('router')->gatherRouteMiddleware($route);
            $this->assertContains('auth', $declaredMiddleware);
            $this->assertContains('tenant', $declaredMiddleware);
            $this->assertContains('password.changed', $declaredMiddleware);
            $this->assertTrue(
                in_array(ValidateCsrfToken::class, $resolvedMiddleware, true)
                    || in_array(VerifyCsrfToken::class, $resolvedMiddleware, true),
            );
        }
        $this->assertContains(
            'throttle:reservation-demand-forecast',
            $routes->get('reservations.demand-forecast.store')->gatherMiddleware(),
        );
    }

    public function test_real_pipeline_changes_only_consultative_forecast_records_and_audits(): void
    {
        $fixture = $this->fixtureWithDeparture();
        $before = $this->businessCounts();

        $this->completeExecution($fixture);

        $this->assertSame($before, $this->businessCounts());
        $this->assertSame(1, DemandHistoryExportRun::withoutGlobalScopes()->count());
        $this->assertSame(1, DemandForecastExecutionRun::withoutGlobalScopes()->count());
        $this->assertSame(1, DemandForecastRun::withoutGlobalScopes()->count());
        $this->assertSame(7, DemandForecast::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.demand_history.exported']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.demand_forecast.execution_queued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.demand_forecast.execution_succeeded']);
    }

    private function completeExecution(array $fixture): DemandForecastExecutionRun
    {
        $this->fakeRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])->postJson(
            route('reservations.demand-forecast.store'),
            ['agency_id' => $fixture['agency']->id],
        )->assertAccepted();
        $execution = DemandForecastExecutionRun::withoutGlobalScopes()->latest('id')->firstOrFail();
        $history = DemandHistoryExportRun::withoutGlobalScopes()
            ->findOrFail($execution->demand_history_export_run_id);
        Process::fake(['*' => Process::result(
            output: json_encode($this->payload($history), JSON_THROW_ON_ERROR),
        )]);
        (new RunDemandForecast($execution->run_id, $execution->tenant_id, $execution->requested_by))
            ->handle(app(ExecuteDemandForecastExecution::class));

        return DemandForecastExecutionRun::withoutGlobalScopes()->findOrFail($execution->id);
    }

    private function fakeRuntime(bool $valid = true): void
    {
        $artifact = $this->mock(DemandForecastModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturn($valid);
        if ($valid) {
            $artifact->shouldReceive('configuredPath')->andReturn('/private/models/demand.joblib');
        }
    }

    private function fixtureWithDeparture(
        string $role = 'tenant-owner',
        string $tenantName = 'Tenant prévision',
    ): array {
        $fixture = $this->fixture($role, $tenantName);
        $this->departure($fixture, $fixture['agency'], Str::upper(Str::random(4)));

        return $fixture;
    }

    private function fixture(
        string $role = 'tenant-owner',
        string $tenantName = 'Tenant prévision',
    ): array {
        $tenant = Tenant::factory()->create([
            'name' => $tenantName.' '.Str::random(6),
            'settings' => ['timezone' => DemandForecastContract::TIMEZONE],
        ]);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence Centre '.Str::random(5)]),
        );
        $user = $this->user(
            compact('tenant', 'agency'),
            $role,
            $role === 'tenant-owner' ? null : $agency,
        );

        return compact('tenant', 'agency', 'user');
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

    private function departure(array $fixture, Agency $agency, string $suffix): RentalContract
    {
        return app(TenantContext::class)->run($fixture['tenant'], function () use (
            $fixture,
            $agency,
            $suffix,
        ): RentalContract {
            $category = VehicleCategory::create([
                'code' => 'PLAN-'.$suffix,
                'name' => 'Catégorie planning '.$suffix,
                'is_active' => true,
            ]);
            $customer = Customer::create([
                'agency_id' => $agency->id,
                'customer_type' => 'individual',
                'first_name' => 'Client',
                'last_name' => 'Planning '.$suffix,
                'verification_status' => 'verified',
            ]);
            $vehicle = Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'RF-PLAN-'.$suffix,
                'brand' => 'RentFleet',
                'model' => 'Planning',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'automatic',
                'current_mileage' => 1000,
            ]);
            $reservation = Reservation::create([
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle->id,
                'reservation_number' => 'RES-PLAN-'.$suffix,
                'starts_at' => '2026-08-24 09:00:00+01',
                'ends_at' => '2026-08-26 09:00:00+01',
                'status' => 'converted',
                'subtotal' => '0.00',
                'options_total' => '0.00',
                'total_amount' => '0.00',
                'deposit_amount' => '0.00',
                'currency' => 'MAD',
                'pricing_snapshot' => [],
                'created_by' => $fixture['user']->id,
            ]);

            return RentalContract::create([
                'agency_id' => $agency->id,
                'reservation_id' => $reservation->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'contract_number' => 'CTR-PLAN-'.$suffix,
                'status' => 'active',
                'expected_start_at' => '2026-08-24 09:00:00+01',
                'expected_return_at' => '2026-08-26 09:00:00+01',
                'actual_start_at' => '2026-08-25 09:00:00+01',
                'rental_subtotal' => '0.00',
                'additional_charges_total' => '0.00',
                'total_amount' => '0.00',
                'deposit_required' => '0.00',
                'currency' => 'MAD',
                'created_by' => $fixture['user']->id,
            ]);
        }, $agency->id);
    }

    /** @return array<string, mixed> */
    private function payload(DemandHistoryExportRun $history): array
    {
        $forecasts = collect(range(1, 7))->map(fn (int $horizon): array => [
            'target_date' => $history->date_to->addDays($horizon)->toDateString(),
            'horizon' => $horizon,
            'vehicle_category' => DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
            'conditional_mean' => number_format(10 + $horizon, 6, '.', ''),
            'p05' => number_format(5 + $horizon, 6, '.', ''),
            'p50' => number_format(9 + $horizon, 6, '.', ''),
            'p90' => number_format(14 + $horizon, 6, '.', ''),
            'p95' => number_format(17 + $horizon, 6, '.', ''),
            'raw_any_crossing' => false,
            'monotone_adjusted' => false,
            'explanations' => [
                ['feature' => 'seasonal_lag_target_minus_7', 'direction' => 'increase', 'prediction_delta' => '1.250000'],
                ['feature' => 'rolling_median_28_at_cutoff', 'direction' => 'decrease', 'prediction_delta' => '-0.750000'],
                ['feature' => 'target_is_weekend', 'direction' => 'neutral', 'prediction_delta' => '0.000000'],
            ],
            'demand_semantics' => DemandForecastContract::TARGET,
            'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
        ])->all();

        $payload = [
            'schema_version' => DemandForecastContract::RESULT_SCHEMA_VERSION,
            'batch_id' => (string) Str::uuid(),
            'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'model' => [
                'name' => DemandForecastContract::MODEL_NAME,
                'version' => DemandForecastContract::MODEL_VERSION,
                'artifact_sha256' => DemandForecastContract::MODEL_ARTIFACT_SHA256,
                'framework' => DemandForecastContract::FRAMEWORK,
                'framework_version' => DemandForecastContract::FRAMEWORK_VERSION,
                'compute' => 'cpu',
                'explanation_method' => DemandForecastContract::EXPLANATION_METHOD,
            ],
            'dataset' => [
                'run_id' => $history->run_id,
                'schema_version' => $history->schema_version,
                'dataset_version' => $history->dataset_version,
                'preprocessing_version' => $history->preprocessing_version,
                'content_sha256' => $history->content_sha256,
                'row_count' => $history->row_count,
                'date_from' => $history->date_from->toDateString(),
                'date_to' => $history->date_to->toDateString(),
                'timezone' => DemandForecastContract::TIMEZONE,
                'distance_unit' => DemandForecastContract::DISTANCE_UNIT,
                'target' => DemandForecastContract::TARGET,
                'vehicle_category' => DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
                'missing_dates' => 'zero_filled',
            ],
            'evaluation' => [
                'validation_scope' => 'public_proxy_only_local_shadow',
                'public_wape' => DemandForecastContract::PUBLIC_WAPE,
                'public_mase' => DemandForecastContract::PUBLIC_MASE,
                'public_interval_coverage_p05_p95' => DemandForecastContract::PUBLIC_INTERVAL_COVERAGE,
                'local_holdout_status' => 'not_available_pending_real_history',
                'local_wape' => null,
                'local_mase' => null,
                'local_interval_coverage_p05_p95' => null,
                'production_claim_allowed' => false,
            ],
            'forecasts' => $forecasts,
            'safety' => [
                'mode' => 'consultative_shadow',
                'human_decision_required' => true,
                'automatic_action_allowed' => false,
                'operational_table_write_allowed' => false,
                'ready_for_production' => false,
                'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
            ],
            'idempotency' => [
                'key' => (string) Str::uuid(),
                'policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY',
                'canonical_payload_sha256' => '',
            ],
        ];
        $payload['idempotency']['canonical_payload_sha256'] = app(
            DemandForecastCanonicalPayload::class,
        )->digest($payload);

        return $payload;
    }

    /** @return array<string, int> */
    private function intelligenceCounts(): array
    {
        return [
            'history' => DemandHistoryExportRun::withoutGlobalScopes()->count(),
            'executions' => DemandForecastExecutionRun::withoutGlobalScopes()->count(),
            'runs' => DemandForecastRun::withoutGlobalScopes()->count(),
            'forecasts' => DemandForecast::withoutGlobalScopes()->count(),
        ];
    }

    /** @return array<string, int> */
    private function businessCounts(): array
    {
        return collect([
            'vehicles',
            'vehicle_blocks',
            'reservations',
            'rental_contracts',
            'pricing_rules',
            'invoices',
            'payments',
            'maintenance_orders',
            'rental_usage_anomaly_runs',
            'vehicle_color_prediction_runs',
            'vehicle_damage_prediction_runs',
            'vehicle_plate_prediction_runs',
            'fleet_reallocation_runs',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
