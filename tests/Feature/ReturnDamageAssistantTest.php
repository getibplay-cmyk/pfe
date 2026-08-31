<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteVehicleDamagePrediction;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Exceptions\VehicleDamageExecutionException;
use App\Jobs\RunVehicleDamagePrediction;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\InspectionItem;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDamagePredictionRun;
use App\Models\VehicleInspection;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\VehicleDamage\SanitizedVehicleDamageImage;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageImageSanitizer;
use App\Support\Intelligence\VehicleDamage\VehicleDamageInputArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageResultValidator;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReturnDamageAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(IntelligencePrivateStorage::DISK);
        $this->seed(RolesPermissionsSeeder::class);
        config([
            'intelligence.vehicle_damage_v1.backend' => VehicleDamageContract::BACKEND_RTDETRV2_S,
            'intelligence.vehicle_damage_v1.enabled' => false,
            'intelligence.vehicle_damage_v1.disk' => IntelligencePrivateStorage::DISK,
            'intelligence.vehicle_damage_v1.python_binary' => 'python',
            'intelligence.vehicle_damage_v1.execution_provider' => 'CPUExecutionProvider',
            'intelligence.vehicle_damage_v1.runtime_script' => base_path(
                'scripts/intelligence/vehicle_damage/run_vehicle_damage_rtdetrv2_onnx.py',
            ),
            'intelligence.vehicle_damage_v1.max_scan_patches' => 1,
            'intelligence.vehicle_damage_v1.image_sanitizer_script' => base_path(
                'scripts/intelligence/vehicle_damage/sanitize_return_image.py',
            ),
            'intelligence.vehicle_damage_v1.model_path' => '/private/models/damage/model.onnx',
            'intelligence.vehicle_damage_v1.model_card_path' => '/private/models/damage/model_card.json',
            'intelligence.vehicle_damage_v1.model_sha256' => str_repeat('a', 64),
            'intelligence.vehicle_damage_v1.model_card_sha256' => str_repeat('b', 64),
            'intelligence.vehicle_damage_v1.rate_limits.user_per_minute' => 100,
            'intelligence.vehicle_damage_v1.rate_limits.scope_per_hour' => 100,
        ]);
    }

    public function test_assistant_is_visible_only_on_an_editable_return_inspection(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();

        $this->actingAs($fixture['user'])
            ->get(route('contracts.show', $fixture['contract']))
            ->assertOk()
            ->assertSee('Aide à l’inspection visuelle')
            ->assertSee('Analyser cette photo')
            ->assertSee('Décision humaine')
            ->assertDontSee('RT-DETR')
            ->assertDontSee('AP50');

        config(['intelligence.vehicle_damage_v1.enabled' => false]);
        $this->actingAs($fixture['user'])
            ->get(route('contracts.show', $fixture['contract']))
            ->assertOk()
            ->assertDontSee('Aide à l’inspection visuelle');
    }

    public function test_post_returns_exact_202_and_creates_only_a_private_preparatory_run(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $before = $this->businessCounts();

        $response = $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => $this->image(),
            ], ['Accept' => 'application/json'])
            ->assertAccepted();

        $run = VehicleDamagePredictionRun::withoutGlobalScopes()->sole();
        $response->assertExactJson([
            'run_id' => $run->run_id,
            'status' => 'queued',
            'status_url' => route('contracts.return-damage-assistant.show', [
                $fixture['contract'],
                'damagePrediction' => $run,
            ]),
        ]);
        $this->assertNull($run->vehicle_inspection_id);
        $this->assertSame($fixture['contract']->id, $run->rental_contract_id);
        $this->assertSame($fixture['vehicle']->id, $run->vehicle_id);
        $this->assertSame($fixture['user']->id, $run->requested_by);
        Storage::disk(IntelligencePrivateStorage::DISK)->assertExists($run->input_stored_path);
        Queue::assertPushed(
            RunVehicleDamagePrediction::class,
            fn (RunVehicleDamagePrediction $job): bool => $job->runId === $run->run_id
                && $job->queue === 'intelligence',
        );
        $this->assertSame($before, $this->businessCounts());
    }

    public function test_multiple_photos_have_independent_runs_and_invalid_uploads_are_refused(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();

        foreach (['a', 'b'] as $name) {
            $this->actingAs($fixture['user'])
                ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                    'image' => UploadedFile::fake()->createWithContent($name.'.png', $this->pngBytes()),
                ], ['Accept' => 'application/json'])->assertAccepted();
        }
        $this->assertSame(2, VehicleDamagePredictionRun::withoutGlobalScopes()->count());
        $this->assertSame(2, VehicleDamagePredictionRun::withoutGlobalScopes()
            ->whereNull('vehicle_inspection_id')->count());

        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [], [
                'Accept' => 'application/json',
            ])->assertUnprocessable();
        config(['intelligence.vehicle_damage_v1.max_upload_kilobytes' => 1]);
        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => UploadedFile::fake()->createWithContent(
                    'large.png',
                    $this->pngBytes().str_repeat('x', 2_048),
                ),
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => UploadedFile::fake()->create('photo.exe', 10, 'application/octet-stream'),
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => UploadedFile::fake()->createWithContent('photo.jpg', 'not-an-image'),
                'vehicle_id' => $fixture['vehicle']->id,
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        config(['intelligence.vehicle_damage_v1.max_upload_kilobytes' => 8_192]);
        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => $this->image(),
                'vehicle_id' => $fixture['vehicle']->id,
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(2, VehicleDamagePredictionRun::withoutGlobalScopes()->count());
    }

    public function test_store_rate_limit_is_actor_and_scope_bounded(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        config([
            'intelligence.vehicle_damage_v1.rate_limits.user_per_minute' => 1,
            'intelligence.vehicle_damage_v1.rate_limits.scope_per_hour' => 10,
        ]);
        $scope = 'tenant:'.$fixture['tenant']->id.'|agency:all';
        $actorKey = 'vehicle-damage-v1:user:'.$scope.'|actor:'.$fixture['user']->id;
        $scopeKey = 'vehicle-damage-v1:scope:'.$scope;
        RateLimiter::clear($actorKey);
        RateLimiter::clear($scopeKey);

        try {
            $this->actingAs($fixture['user'])
                ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                    'image' => $this->image(),
                ], ['Accept' => 'application/json'])
                ->assertAccepted();
            $this->actingAs($fixture['user'])
                ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                    'image' => $this->image(),
                ], ['Accept' => 'application/json'])
                ->assertStatus(429);
            $this->assertSame(1, VehicleDamagePredictionRun::withoutGlobalScopes()->count());
        } finally {
            RateLimiter::clear($actorKey);
            RateLimiter::clear($scopeKey);
        }
    }

    public function test_runtime_failure_is_safe_and_manual_return_remains_available(): void
    {
        $fixture = $this->fixture();
        config(['intelligence.vehicle_damage_v1.enabled' => true]);

        $response = $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => $this->image(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'La photo n’a pas pu être analysée. Poursuivez l’inspection manuelle.',
            ]);

        $this->assertStringNotContainsString('runtime', mb_strtolower($response->getContent()));
        $this->assertSame(0, VehicleDamagePredictionRun::withoutGlobalScopes()->count());
        $this->actingAs($fixture['user'])
            ->get(route('contracts.show', $fixture['contract']))
            ->assertSee('Terminer le retour');
    }

    public function test_status_has_a_closed_client_contract_for_detection_and_no_detection(): void
    {
        $fixture = $this->fixture();
        $detected = $this->completedRun($fixture, true);

        $response = $this->actingAs($fixture['user'])
            ->getJson(route('contracts.return-damage-assistant.show', [
                $fixture['contract'],
                'damagePrediction' => $detected,
            ]))
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'detections' => [['type', 'label', 'confidence', 'box' => ['x', 'y', 'width', 'height']]],
                'message',
                'notice',
                'preview_url',
            ])
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('detections.0.type', 'possible_damage')
            ->assertJsonPath('detections.0.label', 'Zone de dommage possible');
        foreach (['RT-DETR', 'runtime', 'checkpoint', 'traceback', 'sha256', 'model'] as $forbidden) {
            $this->assertStringNotContainsString(mb_strtolower($forbidden), mb_strtolower($response->getContent()));
        }

        $notDetected = $this->completedRun($fixture, false);
        $this->actingAs($fixture['user'])
            ->getJson(route('contracts.return-damage-assistant.show', [
                $fixture['contract'],
                'damagePrediction' => $notDetected,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'detections')
            ->assertJsonPath(
                'message',
                'Aucun dommage n’a été suggéré sur cette photo. Poursuivez l’inspection visuelle du véhicule.',
            );
    }

    public function test_unknown_classes_non_finite_confidence_and_invalid_boxes_are_rejected(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => $this->image(),
            ], ['Accept' => 'application/json'])
            ->assertAccepted();
        $run = VehicleDamagePredictionRun::withoutGlobalScopes()->sole();
        $valid = json_decode($this->resultJson($run, true), true, flags: JSON_THROW_ON_ERROR);
        $mutations = [
            'unknown_class' => function (array $payload): array {
                $payload['result']['candidate_regions'][0]['class'] = 'unknown';

                return $payload;
            },
            'nan_confidence' => function (array $payload): array {
                $payload['result']['candidate_regions'][0]['probability'] = 'NaN';

                return $payload;
            },
            'infinite_confidence' => function (array $payload): array {
                $payload['result']['max_probability_damage'] = 'INF';

                return $payload;
            },
            'out_of_range_confidence' => function (array $payload): array {
                $payload['result']['candidate_regions'][0]['probability'] = 1.1;

                return $payload;
            },
            'negative_box' => function (array $payload): array {
                $payload['result']['candidate_regions'][0]['x'] = -1;

                return $payload;
            },
            'outside_box' => function (array $payload): array {
                $payload['result']['candidate_regions'][0]['width'] = 769;

                return $payload;
            },
            'empty_box' => function (array $payload): array {
                $payload['result']['candidate_regions'][0]['height'] = 0;

                return $payload;
            },
            'partial_payload' => function (array $payload): array {
                unset($payload['result']['candidate_regions'][0]['width']);

                return $payload;
            },
        ];

        foreach ($mutations as $name => $mutate) {
            try {
                app(VehicleDamageResultValidator::class)->validate(
                    json_encode($mutate($valid), JSON_THROW_ON_ERROR),
                    $run,
                );
                $this->fail($name.' devait être refusé.');
            } catch (VehicleDamageExecutionException $exception) {
                $this->assertSame('DAMAGE_OUTPUT_RESULT_INVALID', $exception->failureCode(), $name);
            }
        }
    }

    public function test_status_and_preview_refuse_anonymous_foreign_contract_and_foreign_requester(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, true);
        $otherUser = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);

        $statusRoute = route('contracts.return-damage-assistant.show', [
            $fixture['contract'],
            'damagePrediction' => $run,
        ]);
        $previewRoute = route('contracts.return-damage-assistant.preview', [
            $fixture['contract'],
            'damagePrediction' => $run,
        ]);
        auth()->logout();
        $this->getJson($statusRoute)->assertUnauthorized();
        $this->actingAs($otherUser)->getJson($statusRoute)->assertNotFound();
        $this->actingAs($otherUser)->get($previewRoute)->assertNotFound();

        $preview = $this->actingAs($fixture['user'])
            ->get($previewRoute)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('private', $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));

        $otherContract = app(TenantContext::class)->run(
            $fixture['tenant'],
            function () use ($fixture): RentalContract {
                $reservation = $fixture['reservation']->replicate();
                $reservation->reservation_number = 'RES-OTHER-'.uniqid();
                $reservation->save();
                $contract = $fixture['contract']->replicate();
                $contract->reservation_id = $reservation->id;
                $contract->contract_number = 'CTR-OTHER-'.uniqid();
                $contract->save();

                return $contract;
            },
            $fixture['agency']->id,
        );
        $this->actingAs($fixture['user'])
            ->getJson(route('contracts.return-damage-assistant.show', [
                $otherContract,
                'damagePrediction' => $run,
            ]))->assertNotFound();

        Storage::disk(IntelligencePrivateStorage::DISK)->delete($run->input_stored_path);
        $this->app->forgetInstance(VehicleDamageInputArtifact::class);
        $this->actingAs($fixture['user'])->getJson($statusRoute)->assertUnprocessable();
        $this->actingAs($fixture['user'])->get($previewRoute)->assertNotFound();
    }

    public function test_human_submission_attaches_only_the_selected_run_without_any_automatic_effect(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, true);
        $before = $this->businessCounts();

        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-inspection', $fixture['contract']), [
                'mileage' => 1300,
                'fuel_level' => '60.00',
                'notes' => 'Observation humaine différente : rayure à vérifier côté droit.',
                'items' => $this->items(),
                'damage_prediction_runs' => [$run->run_id],
            ])->assertRedirect();

        $inspection = VehicleInspection::withoutGlobalScopes()
            ->where('rental_contract_id', $fixture['contract']->id)
            ->where('inspection_type', InspectionType::Return->value)
            ->sole();
        $this->assertSame(
            'Observation humaine différente : rayure à vérifier côté droit.',
            $inspection->notes,
        );
        $this->assertSame(
            $inspection->id,
            VehicleDamagePredictionRun::withoutGlobalScopes()->findOrFail($run->id)->vehicle_inspection_id,
        );
        $after = $this->businessCounts();
        $this->assertSame($before['damage_reports'], $after['damage_reports']);
        $this->assertSame($before['contract_charges'], $after['contract_charges']);
        $this->assertSame($before['invoices'], $after['invoices']);
        $this->assertSame($before['payments'], $after['payments']);
        $this->assertSame($before['maintenance_orders'], $after['maintenance_orders']);
        $this->assertSame($before['vehicles'], $after['vehicles']);
        $this->assertSame('return_pending', $fixture['contract']->refresh()->status->value);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.vehicle_damage.preparation_linked',
            'auditable_id' => $run->id,
        ]);
    }

    public function test_foreign_requester_run_rolls_back_the_complete_return_transaction(): void
    {
        $fixture = $this->fixture();
        $otherUser = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);
        $run = $this->completedRun($fixture, true, $otherUser);

        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-inspection', $fixture['contract']), [
                'mileage' => 1300,
                'fuel_level' => '60.00',
                'notes' => 'Cette écriture doit être annulée.',
                'items' => $this->items(),
                'damage_prediction_runs' => [$run->run_id],
            ])->assertSessionHasErrors('damage_prediction_runs');

        $this->assertSame('active', $fixture['contract']->refresh()->status->value);
        $this->assertSame(0, VehicleInspection::withoutGlobalScopes()
            ->where('rental_contract_id', $fixture['contract']->id)
            ->where('inspection_type', InspectionType::Return->value)
            ->count());
        $this->assertNull($run->refresh()->vehicle_inspection_id);
    }

    public function test_migration_backfills_historical_terminal_runs_before_replacing_the_guard(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, true);

        $this->actingAs($fixture['user'])
            ->post(route('contracts.return-inspection', $fixture['contract']), [
                'mileage' => 1300,
                'fuel_level' => '60.00',
                'notes' => 'Observation humaine conservée pendant la migration.',
                'items' => $this->items(),
                'damage_prediction_runs' => [$run->run_id],
            ])->assertRedirect();

        $migration = require database_path(
            'migrations/2026_08_30_000003_allow_preparatory_vehicle_damage_predictions.php',
        );
        $migration->down();
        $this->assertFalse(Schema::hasColumn(
            'vehicle_damage_prediction_runs',
            'rental_contract_id',
        ));

        $migration->up();

        $this->assertTrue(Schema::hasColumn(
            'vehicle_damage_prediction_runs',
            'rental_contract_id',
        ));
        $this->assertSame(
            $fixture['contract']->id,
            DB::table('vehicle_damage_prediction_runs')
                ->where('id', $run->id)
                ->value('rental_contract_id'),
        );
    }

    public function test_routes_keep_web_security_and_the_departure_form_has_no_assistant(): void
    {
        $fixture = $this->fixture();
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->keyBy(fn ($route) => $route->getName());

        foreach ([
            'contracts.return-damage-assistant.store',
            'contracts.return-damage-assistant.show',
            'contracts.return-damage-assistant.preview',
        ] as $name) {
            $middleware = $routes->get($name)?->gatherMiddleware() ?? [];
            $this->assertContains('auth', $middleware);
            $this->assertContains('tenant', $middleware);
            $this->assertContains('password.changed', $middleware);
        }
        $this->assertContains(
            'throttle:vehicle-damage-v1',
            $routes->get('contracts.return-damage-assistant.store')->gatherMiddleware(),
        );

        $fixture['contract']->forceFill(['status' => 'accepted'])->save();
        config(['intelligence.vehicle_damage_v1.enabled' => true]);
        $this->actingAs($fixture['user'])
            ->get(route('contracts.show', $fixture['contract']))
            ->assertOk()
            ->assertSee('Activer le contrat')
            ->assertDontSee('Aide à l’inspection visuelle');
    }

    private function completedRun(
        array $fixture,
        bool $detected,
        ?User $actor = null,
    ): VehicleDamagePredictionRun {
        $actor ??= $fixture['user'];
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($actor)
            ->post(route('contracts.return-damage-assistant.store', $fixture['contract']), [
                'image' => $this->image(),
            ], ['Accept' => 'application/json'])->assertAccepted();
        $run = VehicleDamagePredictionRun::withoutGlobalScopes()->latest('id')->firstOrFail();
        Process::fake(['*' => Process::result(output: $this->resultJson($run, $detected))]);
        (new RunVehicleDamagePrediction($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteVehicleDamagePrediction::class));

        return VehicleDamagePredictionRun::withoutGlobalScopes()->findOrFail($run->id);
    }

    private function enableRuntime(): void
    {
        config(['intelligence.vehicle_damage_v1.enabled' => true]);
        $artifact = $this->mock(VehicleDamageModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturnTrue();
        $artifact->shouldReceive('configuredModelPath')->andReturn('/private/models/damage/model.onnx');
        $artifact->shouldReceive('configuredModelCardPath')->andReturn('/private/models/damage/model_card.json');
        $artifact->shouldReceive('configuredModelSha256')->andReturn(str_repeat('a', 64));
        $artifact->shouldReceive('configuredModelCardSha256')->andReturn(str_repeat('b', 64));
        $sanitizer = $this->mock(VehicleDamageImageSanitizer::class);
        $sanitizer->shouldReceive('sanitize')->andReturnUsing(function (): SanitizedVehicleDamageImage {
            $contents = $this->jpegBytes();

            return new SanitizedVehicleDamageImage(
                contents: $contents,
                mime: 'image/jpeg',
                extension: 'jpg',
                bytes: strlen($contents),
                sha256: hash('sha256', $contents),
                width: 768,
                height: 768,
            );
        });
        $input = $this->mock(VehicleDamageInputArtifact::class);
        $input->shouldReceive('valid')->andReturnTrue();
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Entreprise retour assisté']);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence retour assisté']),
        );
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => Role::where('slug', 'tenant-owner')->value('id'),
            'must_change_password' => false,
        ]);

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $agency, $user): array {
            $category = VehicleCategory::create([
                'code' => 'RETURN-'.uniqid(),
                'name' => 'Catégorie retour',
                'is_active' => true,
            ]);
            $vehicle = Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'RF-RETURN-'.uniqid(),
                'brand' => 'RentFleet',
                'model' => 'Retour assisté',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'automatic',
                'current_mileage' => 1000,
                'status' => 'rented',
            ]);
            $customer = Customer::create([
                'agency_id' => $agency->id,
                'customer_type' => 'individual',
                'first_name' => 'Client',
                'last_name' => 'Fictif',
                'verification_status' => 'verified',
            ]);
            $reservation = Reservation::create([
                'agency_id' => $agency->id,
                'customer_id' => $customer->id,
                'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle->id,
                'reservation_number' => 'RES-RETURN-'.uniqid(),
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->subDay(),
                'status' => 'converted',
                'subtotal' => '500.00',
                'options_total' => '0.00',
                'total_amount' => '500.00',
                'deposit_amount' => '0.00',
                'currency' => 'MAD',
                'pricing_snapshot' => [],
                'created_by' => $user->id,
            ]);
            $contract = RentalContract::create([
                'agency_id' => $agency->id,
                'reservation_id' => $reservation->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'contract_number' => 'CTR-RETURN-'.uniqid(),
                'status' => 'active',
                'expected_start_at' => now()->subDays(2),
                'expected_return_at' => now()->subDay(),
                'actual_start_at' => now()->subDays(2),
                'start_mileage' => 1000,
                'start_fuel_level' => '80.00',
                'rental_subtotal' => '500.00',
                'additional_charges_total' => '0.00',
                'total_amount' => '500.00',
                'deposit_required' => '0.00',
                'currency' => 'MAD',
                'created_by' => $user->id,
            ]);
            $departure = VehicleInspection::create([
                'agency_id' => $agency->id,
                'rental_contract_id' => $contract->id,
                'vehicle_id' => $vehicle->id,
                'inspection_type' => InspectionType::Departure,
                'status' => InspectionStatus::Draft,
                'inspected_at' => now()->subDays(2),
                'mileage' => 1000,
                'fuel_level' => '80.00',
                'created_by' => $user->id,
            ]);
            foreach ($this->items() as $item) {
                InspectionItem::create([...$item, 'vehicle_inspection_id' => $departure->id]);
            }
            $departure->forceFill([
                'status' => InspectionStatus::Completed,
                'completed_by' => $user->id,
                'completed_at' => now()->subDays(2),
            ])->save();

            return compact(
                'tenant',
                'agency',
                'user',
                'category',
                'vehicle',
                'customer',
                'reservation',
                'contract',
                'departure',
            );
        }, $agency->id);
    }

    private function items(): array
    {
        return [
            ['item_code' => 'body', 'label' => 'Carrosserie', 'condition' => 'good'],
            ['item_code' => 'interior', 'label' => 'Habitacle', 'condition' => 'good'],
            ['item_code' => 'tyres', 'label' => 'Pneus', 'condition' => 'good'],
            ['item_code' => 'equipment', 'label' => 'Équipements', 'condition' => 'good'],
        ];
    }

    private function businessCounts(): array
    {
        return [
            'damage_reports' => DB::table('damage_reports')->count(),
            'contract_charges' => DB::table('contract_charges')->count(),
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('payments')->count(),
            'maintenance_orders' => DB::table('maintenance_orders')->count(),
            'vehicles' => DB::table('vehicles')->count(),
        ];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('retour.png', $this->pngBytes());
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }

    private function jpegBytes(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==',
            true,
        );
    }

    private function resultJson(VehicleDamagePredictionRun $run, bool $detected): string
    {
        return json_encode([
            'schema_version' => VehicleDamageContract::RESULT_SCHEMA_VERSION,
            'model' => [
                'name' => VehicleDamageContract::modelName(),
                'version' => VehicleDamageContract::modelVersion(),
                'artifact_sha256' => $run->model_artifact_sha256,
                'model_card_sha256' => $run->model_card_sha256,
                'decision_threshold' => VehicleDamageContract::decisionThreshold(),
            ],
            'input' => [
                'run_id' => $run->run_id,
                'sha256' => $run->input_sha256,
                'bytes' => $run->input_bytes,
                'mime' => $run->input_mime,
                'width' => $run->input_width,
                'height' => $run->input_height,
            ],
            'quality' => [
                'status' => 'usable',
                'reasons' => [],
                'brightness' => 0.5,
                'contrast' => 0.2,
                'sharpness' => 0.3,
            ],
            'scan' => [
                'mode' => VehicleDamageContract::scanMode(),
                'evaluated_patches' => 1,
                'overlap_ratio' => VehicleDamageContract::overlapRatio(),
                'candidate_limit' => VehicleDamageContract::MAX_CANDIDATES,
            ],
            'result' => [
                'suggested_damage' => $detected,
                'max_probability_damage' => $detected ? 0.91 : 0.1,
                'candidate_count' => $detected ? 1 : 0,
                'candidate_regions' => $detected ? [[
                    'x' => 0,
                    'y' => 0,
                    'width' => 300,
                    'height' => 300,
                    'probability' => 0.91,
                ]] : [],
            ],
            'safety' => [
                'mode' => VehicleDamageContract::MODE,
                'human_validation_required' => true,
                'automatic_business_action_allowed' => false,
                'operational_effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
                'local_pilot_required' => true,
                'domain_validation_status' => VehicleDamageContract::DOMAIN_VALIDATION_STATUS,
                'pixel_precise_localization' => false,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
