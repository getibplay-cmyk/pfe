<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteVehiclePlatePrediction;
use App\Enums\VehiclePlatePredictionStatus;
use App\Jobs\RunVehiclePlatePrediction;
use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleColorPredictionReview;
use App\Models\VehicleColorPredictionRun;
use App\Models\VehiclePlatePredictionReview;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehiclePlate\SanitizedVehiclePlateImage;
use App\Support\Intelligence\VehiclePlate\ValidatedVehiclePlateDetection;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateImageSanitizer;
use App\Support\Intelligence\VehiclePlate\VehiclePlateInputArtifact;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleRegistrationAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('rentfleet_test', $this->assertUsesAuthorizedPostgreSqlTestDatabase());
        Storage::fake(IntelligencePrivateStorage::DISK);
        $this->seed(RolesPermissionsSeeder::class);
        config([
            'intelligence.vehicle_plate_hybrid_review.enabled' => false,
            'intelligence.vehicle_plate_hybrid_review.disk' => IntelligencePrivateStorage::DISK,
            'intelligence.vehicle_plate_hybrid_review.python_binary' => 'python',
            'intelligence.vehicle_plate_hybrid_review.device' => 'cpu',
            'intelligence.vehicle_plate_hybrid_review.runtime_timeout_seconds' => 120,
            'intelligence.vehicle_plate_hybrid_review.runtime_script' => base_path(
                'scripts/intelligence/vehicle_plate/hybrid_ocr_worker.py',
            ),
            'intelligence.vehicle_plate_hybrid_review.image_sanitizer_script' => base_path(
                'scripts/intelligence/color_v8/sanitize_vehicle_image.py',
            ),
            'intelligence.vehicle_plate_hybrid_review.detector.python_binary' => 'python',
            'intelligence.vehicle_plate_hybrid_review.detector.device' => 'cpu',
            'intelligence.vehicle_plate_hybrid_review.detector.timeout_seconds' => 180,
            'intelligence.vehicle_plate_hybrid_review.detector.threshold' => 0.075,
            'intelligence.vehicle_plate_hybrid_review.detector.crop_padding_ratio' => 0.04,
            'intelligence.vehicle_plate_hybrid_review.detector.runtime_script' => base_path(
                'scripts/intelligence/vehicle_plate/plate_detector_worker.py',
            ),
            'intelligence.vehicle_plate_hybrid_review.detector.model_path' => storage_path(
                'app/private/intelligence/models/vehicle-plate/detector-test.pt',
            ),
            'intelligence.vehicle_plate_hybrid_review.detector.model_sha256' => str_repeat('a', 64),
            'intelligence.vehicle_plate_hybrid_review.rate_limits.user_per_minute' => 100,
            'intelligence.vehicle_plate_hybrid_review.rate_limits.scope_per_hour' => 1000,
        ]);
    }

    public function test_create_form_shows_both_independent_assistants_only_to_authorized_users(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        config(['intelligence.vehicle_color_v8.enabled' => true]);

        $response = $this->actingAs($fixture['user'])
            ->get(route('vehicles.create'))
            ->assertOk()
            ->assertSee('Photo pour lire l’immatriculation')
            ->assertSee('Photo complète du véhicule')
            ->assertSee('Photo rapprochée de la plaque')
            ->assertSee('Lire l’immatriculation')
            ->assertSee('Analyser la couleur')
            ->assertSee('name="registration_number"', false)
            ->assertSee('name="plate_prediction_run"', false)
            ->assertSee('vehicleRegistrationAssistant', false)
            ->assertSee('vehicleColorAssistant', false);

        foreach (['ANPR', 'OCR', 'PaddleOCR', 'Faster R-CNN', 'checkpoint', 'runtime', 'worker', 'queue', 'SHA'] as $term) {
            $response->assertDontSee($term, false);
        }

        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $this->actingAs($viewer)->get(route('vehicles.create'))->assertForbidden();
    }

    public function test_disabled_feature_never_blocks_manual_vehicle_creation(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])
            ->get(route('vehicles.create'))
            ->assertOk()
            ->assertDontSee('Lire l’immatriculation')
            ->assertSee('name="registration_number"', false);

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), $this->vehicleData($fixture, 'MANUAL-PLATE-001'))
            ->assertRedirect();

        $this->assertDatabaseHas('vehicles', ['registration_number' => 'MANUAL-PLATE-001']);
        $this->assertSame(0, VehiclePlatePredictionRun::withoutGlobalScopes()->count());
    }

    public function test_both_upload_modes_create_only_private_preparatory_runs_and_jobs(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();

        foreach (VehiclePlateDetectorContract::INPUT_KINDS as $inputKind) {
            $response = $this->actingAs($fixture['user'])
                ->withHeader('Accept', 'application/json')
                ->post(route('vehicles.registration-assistant.store'), [
                    'agency_id' => $fixture['agency']->id,
                    'input_kind' => $inputKind,
                    'image' => $this->upload(),
                ])->assertStatus(202)
                ->assertJson(['status' => 'queued']);

            $this->assertSame(['run_id', 'status', 'status_url'], array_keys($response->json()));
        }

        $runs = VehiclePlatePredictionRun::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(0, Vehicle::withoutGlobalScopes()->count());
        foreach ($runs as $run) {
            $this->assertNull($run->vehicle_id);
            $this->assertSame($fixture['agency']->id, $run->agency_id);
            $this->assertSame($fixture['user']->id, $run->requested_by);
            Storage::disk(IntelligencePrivateStorage::DISK)->assertExists($run->input_stored_path);
            $this->assertFalse(Storage::disk('public')->exists($run->input_stored_path));
        }
        Queue::assertPushed(RunVehiclePlatePrediction::class, 2);
        Queue::assertPushed(
            RunVehiclePlatePrediction::class,
            fn (RunVehiclePlatePrediction $job): bool => $job->queue === 'intelligence',
        );
    }

    public function test_invalid_files_forbidden_fields_and_authentication_are_rejected(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $url = route('vehicles.registration-assistant.store');

        $this->withHeader('Accept', 'application/json')
            ->post($url, [
                'agency_id' => $fixture['agency']->id,
                'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
                'image' => $this->upload(),
            ])->assertUnauthorized();

        foreach ([
            'absent' => [],
            'corrupt' => ['image' => UploadedFile::fake()->createWithContent('corrupt.png', 'not-an-image')],
            'fake-mime' => ['image' => UploadedFile::fake()->createWithContent('fake.jpg', $this->gifBytes())],
            'forbidden-type' => ['image' => UploadedFile::fake()->createWithContent('plate.gif', $this->gifBytes())],
            'oversized' => ['image' => UploadedFile::fake()->create(
                'oversized.jpg',
                (int) config('intelligence.vehicle_plate_hybrid_review.max_upload_kilobytes') + 1,
                'image/jpeg',
            )],
        ] as $case => $invalid) {
            $response = $this->actingAs($fixture['user'])
                ->withHeader('Accept', 'application/json')
                ->post($url, [
                    'agency_id' => $fixture['agency']->id,
                    'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
                    ...$invalid,
                ]);
            $this->assertSame(422, $response->status(), $case);
            $response->assertJsonValidationErrors('image');
        }

        $maxDimension = (int) config('intelligence.vehicle_plate_hybrid_review.max_image_dimension');
        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post($url, [
                'agency_id' => $fixture['agency']->id,
                'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
                'image' => UploadedFile::fake()->image('too-wide.png', $maxDimension + 1, 1),
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        foreach (['tenant_id', 'vehicle_id', 'run_id', 'python_binary'] as $forbidden) {
            $this->actingAs($fixture['user'])
                ->withHeader('Accept', 'application/json')
                ->post($url, [
                    'agency_id' => $fixture['agency']->id,
                    'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
                    'image' => $this->upload(),
                    $forbidden => 'forbidden',
                ])->assertUnprocessable()
                ->assertJsonValidationErrors($forbidden);
        }

        $this->assertSame(0, VehiclePlatePredictionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_routes_keep_csrf_auth_tenant_password_and_rate_limit_guards(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        config(['intelligence.vehicle_plate_hybrid_review.rate_limits.user_per_minute' => 1]);

        $route = app('router')->getRoutes()->getByName('vehicles.registration-assistant.store');
        $this->assertNotNull($route);
        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant', $route->gatherMiddleware());
        $this->assertContains('password.changed', $route->gatherMiddleware());
        $this->assertTrue(
            in_array(ValidateCsrfToken::class, $middleware, true)
                || in_array(VerifyCsrfToken::class, $middleware, true),
        );

        $passwordBlocked = $this->user($fixture, 'tenant-owner', null, true);
        $this->actingAs($passwordBlocked)
            ->post(route('vehicles.registration-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
                'image' => $this->upload(),
            ])->assertRedirect(route('password.change-required'));

        $inactive = $this->user($fixture, 'tenant-owner', null);
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)
            ->post(route('vehicles.registration-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
                'image' => $this->upload(),
            ])->assertForbidden();

        $payload = [
            'agency_id' => $fixture['agency']->id,
            'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
            'image' => $this->upload(),
        ];
        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.registration-assistant.store'), $payload)
            ->assertStatus(202);
        $payload['image'] = $this->upload();
        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.registration-assistant.store'), $payload)
            ->assertTooManyRequests();
    }

    public function test_feature_and_runtime_fail_closed_with_only_a_client_safe_message(): void
    {
        $fixture = $this->fixture();
        Queue::fake();
        $payload = [
            'agency_id' => $fixture['agency']->id,
            'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
            'image' => $this->upload(),
        ];

        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.registration-assistant.store'), $payload)
            ->assertForbidden();

        config(['intelligence.vehicle_plate_hybrid_review.enabled' => true]);
        $runtime = $this->mock(VehiclePlateHybridRuntime::class);
        $runtime->shouldReceive('configured')->andReturnFalse();
        $response = $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.registration-assistant.store'), [
                ...$payload,
                'image' => $this->upload(),
            ])->assertStatus(503)
            ->assertExactJson([
                'message' => 'L’immatriculation n’a pas pu être lue. Saisissez-la manuellement.',
            ]);

        foreach ($this->technicalTerms() as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $response->getContent());
        }
        $this->assertSame(0, VehiclePlatePredictionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_status_contract_covers_queued_running_failed_complete_and_partial_states(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $run = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::FULL_IMAGE);

        $this->actingAs($fixture['user'])
            ->getJson(route('vehicles.registration-assistant.show', $run))
            ->assertExactJson([
                'status' => 'queued',
                'suggestion' => null,
                'confidence' => null,
                'displayable' => false,
                'requires_close_up' => false,
                'message' => 'Lecture de la photo en cours…',
            ]);

        $this->markRunning($run);
        $this->actingAs($fixture['user'])
            ->getJson(route('vehicles.registration-assistant.show', $run))
            ->assertExactJson([
                'status' => 'running',
                'suggestion' => null,
                'confidence' => null,
                'displayable' => false,
                'requires_close_up' => false,
                'message' => 'Lecture de la photo en cours…',
            ]);

        $this->markFailed($run, 'PLATE_NOT_DETECTED');
        $failed = $this->actingAs($fixture['user'])
            ->getJson(route('vehicles.registration-assistant.show', $run))
            ->assertExactJson([
                'status' => 'failed',
                'suggestion' => null,
                'confidence' => null,
                'displayable' => false,
                'requires_close_up' => true,
                'message' => 'Plaque non détectée. Ajoutez une photo rapprochée de la plaque.',
            ]);

        $complete = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);
        $this->markSucceeded($complete, 'complete_primary_suggestion', 0.05);
        $completeResponse = $this->actingAs($fixture['user'])
            ->getJson(route('vehicles.registration-assistant.show', $complete))
            ->assertExactJson([
                'status' => 'succeeded',
                'suggestion' => ['value' => '12345|أ|7', 'label' => '12345 | أ | 7'],
                'confidence' => 0.05,
                'displayable' => true,
                'requires_close_up' => false,
                'message' => 'Vérifiez l’immatriculation avant d’enregistrer le véhicule.',
            ]);

        $partial = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);
        $this->markSucceeded($partial, 'partial_segmented_suggestion', 0.7);
        $partialResponse = $this->actingAs($fixture['user'])
            ->getJson(route('vehicles.registration-assistant.show', $partial))
            ->assertExactJson([
                'status' => 'succeeded',
                'suggestion' => null,
                'confidence' => null,
                'displayable' => false,
                'requires_close_up' => true,
                'message' => 'Lecture incomplète. Vérifiez manuellement ou essayez une photo rapprochée.',
            ]);

        foreach ([$failed, $completeResponse, $partialResponse] as $response) {
            foreach (['input_stored_path', 'failure_code', 'detector_bbox', 'sha256', 'exception', 'traceback'] as $key) {
                $this->assertStringNotContainsStringIgnoringCase($key, $response->getContent());
            }
        }
    }

    public function test_status_is_strictly_tenant_agency_and_requester_scoped(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $run = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);

        $otherUser = $this->user($fixture, 'tenant-owner', null);
        $this->actingAs($otherUser)
            ->getJson(route('vehicles.registration-assistant.show', $run))
            ->assertForbidden();

        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(),
        );
        $otherManager = $this->user($fixture, 'agency-manager', $otherAgency);
        $this->actingAs($otherManager)
            ->getJson(route('vehicles.registration-assistant.show', $run))
            ->assertForbidden();

        $foreign = $this->fixture();
        $this->actingAs($foreign['user'])
            ->getJson(route('vehicles.registration-assistant.show', $run))
            ->assertNotFound();

        $this->actingAs($otherManager)
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.registration-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'input_kind' => VehiclePlateDetectorContract::PLATE_CROP,
                'image' => $this->upload(),
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('agency_id');
    }

    public function test_manual_registration_remains_final_and_only_a_complete_owned_run_is_attached(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $run = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);
        $this->markSucceeded($run, 'complete_primary_suggestion', 0.96);

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'HUMAN-REGISTRATION-42'),
                'plate_prediction_run' => $run->run_id,
            ])->assertRedirect();

        $vehicle = Vehicle::withoutGlobalScopes()
            ->where('registration_number', 'HUMAN-REGISTRATION-42')
            ->firstOrFail();
        $linked = VehiclePlatePredictionRun::withoutGlobalScopes()->findOrFail($run->id);
        $this->assertSame('HUMAN-REGISTRATION-42', $vehicle->registration_number);
        $this->assertSame($vehicle->id, $linked->vehicle_id);
        $this->assertSame(0, VehiclePlatePredictionReview::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.vehicle_plate.preparation_linked',
            'auditable_id' => $run->id,
        ]);

        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'SECOND-ATTACH-REFUSED'),
                'plate_prediction_run' => $run->run_id,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('plate_prediction_run');
        $this->assertDatabaseMissing('vehicles', ['registration_number' => 'SECOND-ATTACH-REFUSED']);

        $secondVehicle = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Vehicle::create([
                ...$this->vehicleData($fixture, 'DB-SECOND-ATTACH'),
                'operational_status' => 'active',
            ]),
        );
        try {
            DB::table('vehicle_plate_prediction_runs')
                ->where('id', $run->id)
                ->update(['vehicle_id' => $secondVehicle->id]);
            $this->fail('PostgreSQL devait refuser un second rattachement.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }

    public function test_queued_partial_foreign_user_and_cross_agency_runs_are_refused_atomically(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $queued = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);
        $partial = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);
        $this->markSucceeded($partial, 'partial_segmented_suggestion', 0.7);
        $otherUser = $this->user($fixture, 'tenant-owner', null);
        $otherOwned = $this->queuedPreparationFor($fixture, $otherUser);
        $this->markSucceeded($otherOwned, 'complete_primary_suggestion', 0.96);
        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(),
        );

        foreach ([
            ['run' => $queued, 'registration' => 'QUEUED-REFUSED', 'agency' => $fixture['agency']->id],
            ['run' => $partial, 'registration' => 'PARTIAL-REFUSED', 'agency' => $fixture['agency']->id],
            ['run' => $otherOwned, 'registration' => 'USER-REFUSED', 'agency' => $fixture['agency']->id],
            ['run' => $partial, 'registration' => 'AGENCY-REFUSED', 'agency' => $otherAgency->id],
        ] as $case) {
            $this->actingAs($fixture['user'])
                ->withHeader('Accept', 'application/json')
                ->post(route('vehicles.store'), [
                    ...$this->vehicleData($fixture, $case['registration']),
                    'agency_id' => $case['agency'],
                    'plate_prediction_run' => $case['run']->run_id,
                ])->assertUnprocessable()
                ->assertJsonValidationErrors('plate_prediction_run');
            $this->assertDatabaseMissing('vehicles', ['registration_number' => $case['registration']]);
        }
    }

    public function test_color_and_registration_runs_attach_in_one_transaction_and_roll_back_together(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $plate = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);
        $this->markSucceeded($plate, 'complete_primary_suggestion', 0.96);
        $color = $this->completedColorPreparation($fixture);

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'BOTH-ASSISTANTS-OK'),
                'color' => 'Bleu humain',
                'color_prediction_run' => $color->run_id,
                'plate_prediction_run' => $plate->run_id,
            ])->assertRedirect();

        $vehicle = Vehicle::withoutGlobalScopes()
            ->where('registration_number', 'BOTH-ASSISTANTS-OK')
            ->firstOrFail();
        $this->assertSame('Bleu humain', $vehicle->color);
        $this->assertSame($vehicle->id, VehicleColorPredictionRun::withoutGlobalScopes()->findOrFail($color->id)->vehicle_id);
        $this->assertSame($vehicle->id, VehiclePlatePredictionRun::withoutGlobalScopes()->findOrFail($plate->id)->vehicle_id);
        $this->assertSame(0, VehicleColorPredictionReview::withoutGlobalScopes()->count());
        $this->assertSame(0, VehiclePlatePredictionReview::withoutGlobalScopes()->count());

        $rollbackColor = $this->completedColorPreparation($fixture);
        $invalidPlate = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);
        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'BOTH-ROLLBACK'),
                'color_prediction_run' => $rollbackColor->run_id,
                'plate_prediction_run' => $invalidPlate->run_id,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('plate_prediction_run');

        $this->assertDatabaseMissing('vehicles', ['registration_number' => 'BOTH-ROLLBACK']);
        $this->assertNull(VehicleColorPredictionRun::withoutGlobalScopes()->findOrFail($rollbackColor->id)->vehicle_id);
        $this->assertNull(VehiclePlatePredictionRun::withoutGlobalScopes()->findOrFail($invalidPlate->id)->vehicle_id);
    }

    public function test_preparatory_jobs_execute_with_vehicle_create_permission_in_both_modes(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $role = $fixture['user']->role()->firstOrFail();
        $role->permissions()->detach(Permission::query()
            ->whereIn('slug', ['prediction.view', 'prediction.plate.review'])
            ->pluck('id'));
        $fixture['user']->unsetRelation('role');

        foreach (VehiclePlateDetectorContract::INPUT_KINDS as $inputKind) {
            $run = $this->queuedPreparation($fixture, $inputKind);
            (new RunVehiclePlatePrediction($run->run_id, $run->tenant_id, $run->requested_by))
                ->handle(app(ExecuteVehiclePlatePrediction::class));
            $completed = VehiclePlatePredictionRun::withoutGlobalScopes()->findOrFail($run->id);
            $this->assertSame(VehiclePlatePredictionStatus::Succeeded, $completed->status);
            $this->assertNull($completed->vehicle_id);
            $this->assertTrue($completed->hasCompleteSuggestion());
        }
    }

    public function test_full_photo_abstention_returns_the_close_up_fallback_without_a_failed_job_record(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime('no_detection');
        Queue::fake();
        $run = $this->queuedPreparation($fixture, VehiclePlateDetectorContract::FULL_IMAGE);
        $job = new RunVehiclePlatePrediction($run->run_id, $run->tenant_id, $run->requested_by);

        $job->handle(app(ExecuteVehiclePlatePrediction::class));

        $this->actingAs($fixture['user'])
            ->getJson(route('vehicles.registration-assistant.show', $run))
            ->assertJson([
                'status' => 'failed',
                'displayable' => false,
                'requires_close_up' => true,
                'message' => 'Plaque non détectée. Ajoutez une photo rapprochée de la plaque.',
            ]);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, Vehicle::withoutGlobalScopes()->count());
    }

    public function test_preparatory_run_is_rendered_safely_in_the_existing_private_register(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $this->queuedPreparation($fixture, VehiclePlateDetectorContract::PLATE_CROP);

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-plates.index'))
            ->assertOk()
            ->assertSee('Préparation d’un nouveau véhicule');
    }

    private function queuedPreparation(array $fixture, string $inputKind): VehiclePlatePredictionRun
    {
        return $this->queuedPreparationFor($fixture, $fixture['user'], $inputKind);
    }

    private function queuedPreparationFor(
        array $fixture,
        User $user,
        string $inputKind = VehiclePlateDetectorContract::PLATE_CROP,
    ): VehiclePlatePredictionRun {
        Queue::fake();
        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.registration-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'input_kind' => $inputKind,
                'image' => $this->upload(),
            ])->assertStatus(202);

        return VehiclePlatePredictionRun::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function markRunning(VehiclePlatePredictionRun $run): void
    {
        DB::table('vehicle_plate_prediction_runs')->where('id', $run->id)->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    private function markFailed(VehiclePlatePredictionRun $run, string $failureCode): void
    {
        DB::table('vehicle_plate_prediction_runs')->where('id', $run->id)->update([
            'status' => 'failed',
            'failure_code' => $failureCode,
            'finished_at' => now(),
        ]);
    }

    private function markSucceeded(
        VehiclePlatePredictionRun $run,
        string $status,
        float $confidence,
    ): void {
        $this->markRunning($run);
        $complete = in_array($status, VehiclePlateHybridContract::COMPLETE_STATUSES, true);
        DB::table('vehicle_plate_prediction_runs')->where('id', $run->id)->update([
            'status' => 'succeeded',
            'suggestion_status' => $status,
            'suggested_canonical' => $complete ? '12345|أ|7' : null,
            'display_text' => $complete ? '12345 | أ | 7' : '12345 | ? | 7',
            'confidence' => $confidence,
            'suggestion_source' => $status === 'complete_primary_suggestion'
                ? 'full_crop_ppocrv5'
                : 'segmented_ppocrv5_fusion',
            'fallback_executed' => $status !== 'complete_primary_suggestion',
            'finished_at' => now(),
        ]);
    }

    private function completedColorPreparation(array $fixture): VehicleColorPredictionRun
    {
        $runId = (string) Str::uuid();
        $probabilities = array_fill_keys(VehicleColorContract::CLASSES, 0.0025);
        $probabilities['black'] = 0.98;

        return app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => VehicleColorPredictionRun::create([
                'agency_id' => $fixture['agency']->id,
                'run_id' => $runId,
                'vehicle_id' => null,
                'requested_by' => $fixture['user']->id,
                'status' => 'succeeded',
                'input_mime' => 'image/png',
                'input_extension' => 'png',
                'input_bytes' => 1,
                'input_sha256' => str_repeat('b', 64),
                'input_stored_path' => 'intelligence/color-v8/inputs/'
                    .$fixture['tenant']->id.'/'.$runId.'.png',
                'suggested_color' => 'black',
                'confidence' => 0.98,
                'model_accepted' => true,
                'probabilities' => $probabilities,
                'model_name' => VehicleColorContract::MODEL_NAME,
                'model_version' => VehicleColorContract::MODEL_VERSION,
                'model_artifact_sha256' => VehicleColorContract::MODEL_ARTIFACT_SHA256,
                'metadata_sha256' => VehicleColorContract::METADATA_SHA256,
                'accepted_threshold' => VehicleColorContract::ACCEPTED_THRESHOLD,
                'operational_effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                'requested_at' => now()->subSecond(),
                'started_at' => now()->subSecond(),
                'finished_at' => now(),
            ]),
        );
    }

    private function enableRuntime(string $detectorStatus = 'detected'): void
    {
        config(['intelligence.vehicle_plate_hybrid_review.enabled' => true]);
        $runtime = $this->mock(VehiclePlateHybridRuntime::class);
        $runtime->shouldReceive('configured')->zeroOrMoreTimes()->andReturnTrue();
        $runtime->shouldReceive('execute')->zeroOrMoreTimes()->andReturnUsing(
            fn (VehiclePlatePredictionRun $run): string => $this->resultJson($run->run_id),
        );
        $detector = $this->mock(VehiclePlateDetectorRuntime::class);
        $detector->shouldReceive('ready')->zeroOrMoreTimes()->andReturnTrue();
        $detector->shouldReceive('execute')->zeroOrMoreTimes()->andReturnUsing(
            fn (): ValidatedVehiclePlateDetection => $this->detectionResult($detectorStatus),
        );
        $contents = $this->jpegBytes();
        $sanitizer = $this->mock(VehiclePlateImageSanitizer::class);
        $sanitizer->shouldReceive('sanitize')->zeroOrMoreTimes()->andReturnUsing(
            static fn () => new SanitizedVehiclePlateImage(
                contents: $contents,
                mime: 'image/jpeg',
                extension: 'jpg',
                bytes: strlen($contents),
                sha256: hash('sha256', $contents),
                width: 4,
                height: 2,
            ),
        );
        $inputArtifact = $this->mock(VehiclePlateInputArtifact::class);
        $inputArtifact->shouldReceive('valid')->zeroOrMoreTimes()->andReturnTrue();
    }

    private function detectionResult(string $status): ValidatedVehiclePlateDetection
    {
        if ($status === 'no_detection') {
            return new ValidatedVehiclePlateDetection(
                status: 'no_detection',
                score: null,
                bbox: null,
                eligibleCount: 0,
                cropContents: null,
                cropBytes: null,
                cropSha256: null,
                cropWidth: null,
                cropHeight: null,
                cropBbox: null,
            );
        }

        $contents = $this->jpegBytes();

        return new ValidatedVehiclePlateDetection(
            status: 'detected',
            score: 0.91,
            bbox: [0.0, 0.0, 4.0, 2.0],
            eligibleCount: 1,
            cropContents: $contents,
            cropBytes: strlen($contents),
            cropSha256: hash('sha256', $contents),
            cropWidth: 4,
            cropHeight: 2,
            cropBbox: [0, 0, 4, 2],
        );
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(),
        );
        $user = $this->user(compact('tenant', 'agency'), 'tenant-owner', null);
        $category = app(TenantContext::class)->run(
            $tenant,
            fn () => VehicleCategory::create([
                'code' => 'REG-'.str_pad((string) $tenant->id, 4, '0', STR_PAD_LEFT),
                'name' => 'Catégorie immatriculation',
                'is_active' => true,
            ]),
        );

        return compact('tenant', 'agency', 'user', 'category');
    }

    private function user(
        array $fixture,
        string $roleSlug,
        ?Agency $agency,
        bool $mustChangePassword = false,
    ): User {
        return User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $agency?->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'must_change_password' => $mustChangePassword,
        ]);
    }

    private function vehicleData(array $fixture, string $registration): array
    {
        return [
            'agency_id' => $fixture['agency']->id,
            'vehicle_category_id' => $fixture['category']->id,
            'registration_number' => $registration,
            'brand' => 'Dacia',
            'model' => 'Sandero',
            'production_year' => 2026,
            'fuel_type' => 'diesel',
            'transmission' => 'manual',
            'color' => 'Gris',
            'current_mileage' => 1000,
        ];
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('vehicle.png', $this->pngBytes());
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        return is_string($bytes) ? $bytes : throw new \LogicException('Fixture PNG invalide.');
    }

    private function gifBytes(): string
    {
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);

        return is_string($bytes) ? $bytes : throw new \LogicException('Fixture GIF invalide.');
    }

    private function jpegBytes(): string
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAACAAQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD9U6KKKAP/2Q==',
            true,
        );

        return is_string($bytes) ? $bytes : throw new \LogicException('Fixture JPEG invalide.');
    }

    private function resultJson(string $cropId): string
    {
        $component = static fn (string $role, string $value): array => [
            'role' => $role,
            'value' => $value,
            'confidence' => 0.96,
            'support' => 1,
            'evidence' => ['full:original'],
            'inferred_from_latin' => false,
        ];

        return json_encode([
            'schema_version' => VehiclePlateHybridContract::RESULT_SCHEMA_VERSION,
            'fallback_version' => VehiclePlateHybridContract::FALLBACK_VERSION,
            'model_name' => VehiclePlateHybridContract::MODEL_NAME,
            'count' => 1,
            'results' => [[
                'crop_id' => $cropId,
                'fallback_executed' => false,
                'suggestion' => [
                    'schema_version' => VehiclePlateHybridContract::SUGGESTION_SCHEMA_VERSION,
                    'status' => 'complete_primary_suggestion',
                    'canonical' => '12345|أ|7',
                    'display_text' => '12345 | أ | 7',
                    'confidence' => 0.96,
                    'confidence_semantics' => VehiclePlateHybridContract::CONFIDENCE_SEMANTICS,
                    'source' => 'full_crop_ppocrv5',
                    'model_name' => VehiclePlateHybridContract::MODEL_NAME,
                    'components' => [
                        $component('serial', '12345'),
                        $component('series', 'أ'),
                        $component('region', '7'),
                    ],
                    'reasons' => ['primary_reading_passed_moroccan_grammar'],
                    'human_review_required' => true,
                    'operational_effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                ],
                'observations' => [
                    [
                        'layout_id' => 'full',
                        'role' => 'full',
                        'variant_id' => 'original',
                        'raw_text' => '7أ12345',
                        'score' => 0.96,
                    ],
                    [
                        'layout_id' => 'full',
                        'role' => 'full',
                        'variant_id' => 'clahe',
                        'raw_text' => '7أ12345',
                        'score' => 0.95,
                    ],
                ],
            ]],
            'status_counts' => ['complete_primary_suggestion' => 1],
            'timings_seconds' => ['ocr_load' => 0.5, 'ocr_inference_total' => 0.1],
            'environment' => [
                'python' => '3.12.13',
                'paddle' => '3.3.0',
                'paddleocr' => '3.7.0',
                'paddle_cuda_compiled' => false,
                'paddle_gpu_count' => 0,
                'device' => 'cpu',
                'isolated_process' => true,
            ],
            'safeguards' => [
                'human_review_required' => true,
                'automatic_vehicle_update_allowed' => false,
                'operational_effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                'second_ocr_model_used' => false,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    private function technicalTerms(): array
    {
        return ['ANPR', 'OCR', 'PaddleOCR', 'Faster R-CNN', 'checkpoint', 'runtime', 'worker', 'queue', 'SHA', 'traceback'];
    }
}
