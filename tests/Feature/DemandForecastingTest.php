<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteDemandForecastExecution;
use App\Enums\DemandForecastExecutionStatus;
use App\Jobs\RunDemandForecast;
use App\Models\Agency;
use App\Models\AuditLog;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemandForecastingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-14 12:00:00+01:00');
        config(['intelligence.export_hmac_key' => str_repeat('demand-test-only-', 4)]);
        config([
            'intelligence.demand_forecasting.model_bundle_path' => storage_path(
                'framework/testing/missing-demand-model.joblib',
            ),
        ]);
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_history_export_uses_local_departures_zero_filling_pseudonyms_and_kilometres(): void
    {
        $fixture = $this->fixture();
        $this->activeContract($fixture, '2026-08-10 23:30:00+00');

        $response = $this->actingAs($fixture['user'])
            ->get(route('intelligence.demand-history.export', $this->filters($fixture['agency'])))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'no-store, private');
        $content = $response->streamedContent();
        $run = DemandHistoryExportRun::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(hash('sha256', $content), $run->content_sha256);
        $this->assertSame(35, $run->row_count);
        $this->assertSame(1, $run->observed_departures_count);
        $this->assertSame('km', $run->distance_unit);
        $this->assertMatchesRegularExpression('/^a_[a-f0-9]{64}$/', $run->agency_key);
        $this->assertMatchesRegularExpression('/^s_[a-f0-9]{64}$/', $run->series_key);
        Storage::disk('local')->assertExists($run->stored_path);

        $lines = preg_split('/\r\n|\n|\r/', substr($content, 3), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertCount(36, $lines);
        $this->assertSame(
            DemandForecastContract::snapshotHeaders(),
            str_getcsv($lines[0], ';', '"', ''),
        );
        $rows = collect(array_slice($lines, 1))->map(function (string $line): array {
            return array_combine(
                DemandForecastContract::snapshotHeaders(),
                str_getcsv($line, ';', '"', ''),
            );
        });
        $this->assertSame('1', $rows->firstWhere('date_local', '2026-08-11')['observed_departures']);
        $this->assertSame(34, $rows->where('observed_departures', '0')->count());
        $this->assertTrue($rows->every(fn (array $row): bool => $row['distance_unit'] === 'km'));
        foreach (['Personne test', 'test@example.invalid', 'RF-DEMAND-001', 'VINDEMAND00000001'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }

        $manifest = json_decode(
            $this->actingAs($fixture['user'])
                ->get(route('intelligence.demand-history.manifest', $run))
                ->assertOk()
                ->streamedContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('observed_departures', $manifest['dataset']['target']);
        $this->assertSame('zero_filled', $manifest['dataset']['missing_dates']);
        $this->assertSame('km', $manifest['units']['distance']);
        $this->assertFalse($manifest['safety']['contains_direct_identifiers']);
    }

    public function test_closed_forecast_batch_is_imported_replayed_and_rendered_without_operational_write(): void
    {
        $fixture = $this->fixture();
        $history = $this->history($fixture);
        $payload = $this->payload($history);
        $before = [
            'reservations' => DB::table('reservations')->count(),
            'rental_contracts' => DB::table('rental_contracts')->count(),
            'vehicles' => DB::table('vehicles')->count(),
            'pricing_rules' => DB::table('pricing_rules')->count(),
        ];

        foreach ([1, 2] as $attempt) {
            $this->actingAs($fixture['user'])
                ->post(route('intelligence.demand-forecasts.store', $history), [
                    'forecast_batch' => $this->jsonFile($payload, 'forecast-'.$attempt.'.json'),
                ])
                ->assertRedirect(route('intelligence.demand-forecasts.index'));
        }

        $conflict = $payload;
        $conflict['batch_id'] = (string) Str::uuid();
        $conflict['forecasts'][0]['conditional_mean'] = '99.000000';
        $conflict = $this->withDigest($conflict);
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecasts.store', $history), [
                'forecast_batch' => $this->jsonFile($conflict, 'conflict.json'),
            ])
            ->assertStatus(409);

        $run = DemandForecastRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, DemandForecastRun::withoutGlobalScopes()->count());
        $this->assertSame(7, DemandForecast::withoutGlobalScopes()->count());
        $this->assertSame('consultative_shadow', $run->mode);
        $this->assertSame('0.152342', $run->public_wape);
        $this->assertSame('not_available_pending_real_history', $run->local_holdout_status);
        $this->assertNull($run->local_wape);
        $this->assertSame('NO_OPERATIONAL_ACTION', $run->operational_effect);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], DemandForecast::withoutGlobalScopes()
            ->orderBy('horizon')->pluck('horizon')->all());
        $this->assertCount(1, Storage::disk('local')->allFiles('intelligence/demand-forecasts'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.demand_forecast.imported']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.demand_forecast.replayed']);

        $page = $this->actingAs($fixture['user'])
            ->get(route('intelligence.demand-forecasts.index'))
            ->assertOk()
            ->assertSee('Prévision de demande D+1 à D+7')
            ->assertSee('15,23 %')
            ->assertSee('84,77 %')
            ->assertSee('Validation locale en attente')
            ->assertSee('scénario central', false)
            ->assertSee('saisonnalité hebdomadaire')
            ->assertSee('+1,25 départ(s)')
            ->assertDontSee('15,234 %')
            ->assertDontSee('10,000000')
            ->assertSee('NO_OPERATIONAL_ACTION');
        $page->assertDontSee($run->stored_path)
            ->assertDontSee($run->model_artifact_sha256);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }

        $audit = AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.demand_forecast.imported')
            ->firstOrFail();
        foreach (['content_sha256', 'stored_path', 'idempotency_key', 'tenant_id', 'agency_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $audit->new_values);
        }
    }

    public function test_saas_queues_and_executes_the_authentic_hgb_boundary_without_operational_write(): void
    {
        $fixture = $this->fixture();
        $history = $this->history($fixture);
        $artifact = $this->mock(DemandForecastModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturnTrue();
        $artifact->shouldReceive('configuredPath')->andReturn('/private/models/authentic-j5.joblib');
        Queue::fake();
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

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertRedirect(route('intelligence.demand-forecasts.index'))
            ->assertSessionHas('status');

        $execution = DemandForecastExecutionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(DemandForecastExecutionStatus::Queued, $execution->status);
        $this->assertSame($history->id, $execution->demand_history_export_run_id);
        $this->assertSame(DemandForecastContract::MODEL_ARTIFACT_SHA256, $execution->model_artifact_sha256);
        $this->assertSame(DemandForecastContract::MODEL_ARTIFACT_BYTES, $execution->model_artifact_bytes);
        Queue::assertPushed(
            RunDemandForecast::class,
            fn (RunDemandForecast $job): bool => $job->runId === $execution->run_id
                && $job->tenantId === $fixture['tenant']->id
                && $job->actorId === $fixture['user']->id
                && $job->queue === 'intelligence',
        );

        Process::fake([
            '*' => Process::result(
                output: json_encode(
                    $this->payload($history),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
            ),
        ]);
        $job = new RunDemandForecast(
            $execution->run_id,
            $fixture['tenant']->id,
            $fixture['user']->id,
        );
        $job->handle(app(ExecuteDemandForecastExecution::class));

        $completed = DemandForecastExecutionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(DemandForecastExecutionStatus::Succeeded, $completed->status);
        $this->assertNotNull($completed->started_at);
        $this->assertNotNull($completed->finished_at);
        $this->assertNotNull($completed->demand_forecast_run_id);
        $this->assertSame(1, DemandForecastRun::withoutGlobalScopes()->count());
        $this->assertSame(7, DemandForecast::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/demand-runtime'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.demand_forecast.execution_queued',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.demand_forecast.execution_succeeded',
        ]);
        Process::assertRan(fn ($process): bool => is_array($process->command)
            && $process->command[0] === config('intelligence.demand_forecasting.python_binary')
            && $process->command[1] === config('intelligence.demand_forecasting.runtime_script')
            && in_array('--stdout', $process->command, true)
            && in_array('/private/models/authentic-j5.joblib', $process->command, true)
            && $process->timeout === 60);

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.demand-forecasts.index'))
            ->assertOk()
            ->assertSee('Inférence HGB réellement exécutée depuis le SaaS')
            ->assertSee($completed->run_id)
            ->assertSee('bundle J5 authentique');

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->assertPostgreSqlConstraint(fn () => DB::table('demand_forecast_execution_runs')
            ->where('id', $completed->id)
            ->delete());
    }

    public function test_missing_or_unverified_hgb_artifact_fails_closed_before_queueing(): void
    {
        $fixture = $this->fixture();
        $history = $this->history($fixture);
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertServiceUnavailable();

        $this->assertSame(0, DemandForecastExecutionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history), [
                'model_path' => '/tmp/untrusted.joblib',
            ])
            ->assertSessionHasErrors('model_path');
        $this->assertSame(0, DemandForecastExecutionRun::withoutGlobalScopes()->count());
    }

    public function test_model_installer_rejects_any_non_j5_file_without_creating_a_target(): void
    {
        $target = storage_path('framework/testing/rejected-demand-model.joblib');
        config(['intelligence.demand_forecasting.model_bundle_path' => $target]);
        if (file_exists($target)) {
            unlink($target);
        }
        $invalid = UploadedFile::fake()->createWithContent(
            'not-the-j5-model.joblib',
            'this is not a serialized model',
        );

        $this->artisan('rentfleet:demand-model:install', [
            'source' => $invalid->getPathname(),
        ])->assertFailed();

        $this->assertFileDoesNotExist($target);
    }

    public function test_hgb_process_failure_is_sanitized_and_persisted_without_result(): void
    {
        $fixture = $this->fixture();
        $history = $this->history($fixture);
        $artifact = $this->mock(DemandForecastModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturnTrue();
        $artifact->shouldReceive('configuredPath')->andReturn('/private/models/authentic-j5.joblib');
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertRedirect();
        $execution = DemandForecastExecutionRun::withoutGlobalScopes()->firstOrFail();

        Process::fake([
            '*' => Process::result(
                errorOutput: 'secret=/private/path database-password=forbidden',
                exitCode: 1,
            ),
        ]);
        $job = new RunDemandForecast(
            $execution->run_id,
            $fixture['tenant']->id,
            $fixture['user']->id,
        );
        $failure = null;
        try {
            $job->handle(app(ExecuteDemandForecastExecution::class));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertNotNull($failure);
        $job->failed($failure);

        $failed = DemandForecastExecutionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(DemandForecastExecutionStatus::Failed, $failed->status);
        $this->assertSame('HGB_PROCESS_FAILED', $failed->failure_code);
        $this->assertSame(0, DemandForecastRun::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandForecast::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/demand-runtime'));
        $this->assertFalse(AuditLog::withoutGlobalScopes()->get()->contains(
            static fn (AuditLog $audit): bool => str_contains(
                json_encode($audit->new_values, JSON_THROW_ON_ERROR),
                'secret=/private/path',
            ),
        ));
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.demand-forecasts.index'))
            ->assertOk()
            ->assertSee('Python ou l’environnement HGB figé n’a pas pu terminer le calcul.')
            ->assertDontSee('secret=/private/path');
    }

    public function test_only_one_active_hgb_run_per_snapshot_and_stale_runs_are_recovered(): void
    {
        $fixture = $this->fixture();
        $history = $this->history($fixture);
        $artifact = $this->mock(DemandForecastModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturnTrue();
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertRedirect();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertStatus(409);
        $this->assertSame(1, DemandForecastExecutionRun::withoutGlobalScopes()->count());

        CarbonImmutable::setTestNow(now()->addMinutes(11));
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertRedirect();

        $runs = DemandForecastExecutionRun::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(DemandForecastExecutionStatus::Failed, $runs[0]->status);
        $this->assertSame('RUN_STALE_RECOVERED', $runs[0]->failure_code);
        $this->assertSame(DemandForecastExecutionStatus::Queued, $runs[1]->status);
        $this->assertSame(2, Queue::pushed(RunDemandForecast::class)->count());
        $this->assertTrue(DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('indexname', 'demand_forecast_exec_one_active_per_history')
            ->exists());
    }

    public function test_history_export_requires_strict_iso_dates_and_a_forward_period(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.demand-history.export', [
                'date_from' => '08/07/2026',
                'date_to' => '2026-08-11',
                'agency_id' => $fixture['agency']->id,
            ]))
            ->assertSessionHasErrors('date_from');

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.demand-history.export', [
                'date_from' => '2026-08-11',
                'date_to' => '2026-07-08',
                'agency_id' => $fixture['agency']->id,
            ]))
            ->assertSessionHasErrors('date_to');

        $this->assertSame(0, DemandHistoryExportRun::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/demand-history'));
    }

    public function test_unknown_fields_miles_wrong_lineage_quantile_crossing_and_local_claim_fail_closed(): void
    {
        $fixture = $this->fixture();
        $history = $this->history($fixture);
        $base = $this->payload($history);

        $unknown = $base;
        $unknown['accuracy'] = '0.99';
        $this->assertInvalid($fixture['user'], $history, $unknown);

        $miles = $base;
        $miles['dataset']['distance_unit'] = 'miles';
        $this->assertInvalid($fixture['user'], $history, $this->withDigest($miles));

        $wrongModel = $base;
        $wrongModel['model']['artifact_sha256'] = str_repeat('0', 64);
        $this->assertInvalid($fixture['user'], $history, $this->withDigest($wrongModel));

        $crossed = $base;
        $crossed['forecasts'][0]['p50'] = '20.000000';
        $crossed['forecasts'][0]['p90'] = '10.000000';
        $this->assertInvalid($fixture['user'], $history, $this->withDigest($crossed));

        $localClaim = $base;
        $localClaim['evaluation']['local_holdout_status'] = 'passed';
        $localClaim['evaluation']['local_wape'] = '0.100000';
        $localClaim['evaluation']['production_claim_allowed'] = true;
        $this->assertInvalid($fixture['user'], $history, $this->withDigest($localClaim));

        Storage::disk('local')->delete($history->stored_path);
        $this->assertInvalid($fixture['user'], $history, $base);

        Storage::disk('local')->put($history->stored_path, 'snapshot-altéré');
        $this->assertInvalid($fixture['user'], $history, $base);

        $this->assertSame(0, DemandForecastRun::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandForecast::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/demand-forecasts'));
    }

    public function test_rbac_tenant_agency_and_append_only_guards_are_enforced(): void
    {
        $fixture = $this->fixture();
        $history = $this->history($fixture);
        $payload = $this->payload($history);
        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $fleetManager = $this->user($fixture, 'fleet-manager', $fixture['agency']);
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $otherFleetManager = $this->user($fixture, 'fleet-manager', $otherAgency);
        $foreign = $this->fixture();

        $this->assertDatabaseHas('permissions', [
            'slug' => 'prediction.forecast.import',
            'group' => 'prediction',
        ]);
        foreach ([
            'intelligence.demand-forecasts.index',
            'intelligence.demand-history.export',
            'intelligence.demand-history.manifest',
            'intelligence.demand-history.download',
            'intelligence.demand-forecasts.store',
            'intelligence.demand-forecast-executions.store',
        ] as $route) {
            $this->assertTrue(app('router')->has($route), $route);
        }
        foreach ([
            'demand_history_export_runs',
            'demand_forecast_runs',
            'demand_forecasts',
            'demand_forecast_execution_runs',
        ] as $table) {
            $this->assertTrue(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists(), $table);
        }

        $this->actingAs($viewer)
            ->get(route('intelligence.demand-forecasts.index'))
            ->assertOk();
        $this->actingAs($viewer)
            ->get(route('intelligence.demand-history.download', $history))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('intelligence.demand-forecasts.store', $history), [
                'forecast_batch' => $this->jsonFile($payload),
            ])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertForbidden();
        $this->actingAs($otherFleetManager)
            ->post(route('intelligence.demand-forecasts.store', $history), [
                'forecast_batch' => $this->jsonFile($payload),
            ])
            ->assertForbidden();
        $this->actingAs($otherFleetManager)
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertForbidden();
        $this->actingAs($foreign['user'])
            ->get(route('intelligence.demand-history.download', $history))
            ->assertNotFound();
        $this->actingAs($foreign['user'])
            ->post(route('intelligence.demand-forecast-executions.store', $history))
            ->assertNotFound();

        $this->actingAs($fleetManager)
            ->get(route('intelligence.demand-history.export', $this->filters($fixture['agency'])))
            ->assertForbidden();
        $this->actingAs($fleetManager)
            ->post(route('intelligence.demand-forecasts.store', $history), [
                'forecast_batch' => $this->jsonFile($payload),
                'tenant_id' => $fixture['tenant']->id,
            ])
            ->assertSessionHasErrors('tenant_id');
        $this->actingAs($fleetManager)
            ->post(route('intelligence.demand-forecasts.store', $history), [
                'forecast_batch' => $this->jsonFile($payload),
            ])
            ->assertRedirect();

        $run = DemandForecastRun::withoutGlobalScopes()->firstOrFail();
        $forecast = DemandForecast::withoutGlobalScopes()->firstOrFail();
        $this->assertPostgreSqlConstraint(fn () => DB::table('demand_history_export_runs')
            ->where('id', $history->id)->update(['distance_unit' => 'miles']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('demand_forecast_runs')
            ->where('id', $run->id)->update(['mode' => 'production']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('demand_forecasts')
            ->where('id', $forecast->id)->delete());
    }

    private function fixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Entreprise test demande',
            'settings' => ['timezone' => 'Africa/Casablanca'],
        ]);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence test demande']),
        );
        $user = $this->user(compact('tenant', 'agency'), $roleSlug, $roleSlug === 'tenant-owner' ? null : $agency);

        return compact('tenant', 'agency', 'user');
    }

    private function user(array $fixture, string $roleSlug, ?Agency $agency): User
    {
        return User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'must_change_password' => false,
        ]);
    }

    private function activeContract(array $fixture, string $actualStartAt): RentalContract
    {
        return app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture, $actualStartAt): RentalContract {
            $category = VehicleCategory::create([
                'code' => 'DEMAND',
                'name' => 'Catégorie test demande',
                'is_active' => true,
            ]);
            $customer = Customer::create([
                'agency_id' => $fixture['agency']->id,
                'customer_type' => 'individual',
                'first_name' => 'Personne',
                'last_name' => 'test',
                'email' => 'test@example.invalid',
                'verification_status' => 'verified',
            ]);
            $vehicle = Vehicle::create([
                'agency_id' => $fixture['agency']->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'RF-DEMAND-001',
                'vin' => 'VINDEMAND00000001',
                'brand' => 'Marque test',
                'model' => 'Modèle test',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'manual',
                'current_mileage' => 1000,
            ]);
            $reservation = Reservation::create([
                'agency_id' => $fixture['agency']->id,
                'customer_id' => $customer->id,
                'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle->id,
                'reservation_number' => 'RES-DEMAND-001',
                'starts_at' => '2026-08-10 23:30:00+00',
                'ends_at' => '2026-08-12 10:00:00+00',
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
                'agency_id' => $fixture['agency']->id,
                'reservation_id' => $reservation->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'contract_number' => 'CTR-DEMAND-001',
                'status' => 'active',
                'expected_start_at' => '2026-08-10 23:30:00+00',
                'expected_return_at' => '2026-08-12 10:00:00+00',
                'actual_start_at' => CarbonImmutable::parse($actualStartAt)
                    ->setTimezone(DemandForecastContract::TIMEZONE),
                'rental_subtotal' => '0.00',
                'additional_charges_total' => '0.00',
                'total_amount' => '0.00',
                'deposit_required' => '0.00',
                'currency' => 'MAD',
                'created_by' => $fixture['user']->id,
            ]);
        }, $fixture['agency']->id);
    }

    private function history(array $fixture): DemandHistoryExportRun
    {
        $this->activeContract($fixture, '2026-08-10 10:00:00+00');
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.demand-history.export', $this->filters($fixture['agency'])))
            ->assertOk()
            ->streamedContent();

        return DemandHistoryExportRun::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(DemandHistoryExportRun $history): array
    {
        $forecasts = [];
        foreach (range(1, 7) as $horizon) {
            $forecasts[] = [
                'target_date' => $history->date_to->addDays($horizon)->toDateString(),
                'horizon' => $horizon,
                'vehicle_category' => 'all',
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
                'demand_semantics' => 'observed_departures',
                'operational_effect' => 'NO_OPERATIONAL_ACTION',
            ];
        }

        return $this->withDigest([
            'schema_version' => '1.0.0',
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
                'timezone' => 'Africa/Casablanca',
                'distance_unit' => 'km',
                'target' => 'observed_departures',
                'vehicle_category' => 'all',
                'missing_dates' => 'zero_filled',
            ],
            'evaluation' => [
                'validation_scope' => 'public_proxy_only_local_shadow',
                'public_wape' => '0.152342',
                'public_mase' => '0.829556',
                'public_interval_coverage_p05_p95' => '0.860700',
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
                'operational_effect' => 'NO_OPERATIONAL_ACTION',
            ],
            'idempotency' => [
                'key' => (string) Str::uuid(),
                'policy' => 'SAME_KEY_SAME_PAYLOAD_ONLY',
                'canonical_payload_sha256' => '',
            ],
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withDigest(array $payload): array
    {
        $payload['idempotency']['canonical_payload_sha256'] = app(DemandForecastCanonicalPayload::class)
            ->digest($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function jsonFile(array $payload, string $name = 'forecast.json'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertInvalid(User $user, DemandHistoryExportRun $history, array $payload): void
    {
        $this->actingAs($user)
            ->post(route('intelligence.demand-forecasts.store', $history), [
                'forecast_batch' => $this->jsonFile($payload),
            ])
            ->assertSessionHasErrors('forecast_batch');
    }

    private function filters(Agency $agency): array
    {
        return [
            'date_from' => '2026-07-08',
            'date_to' => '2026-08-11',
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
