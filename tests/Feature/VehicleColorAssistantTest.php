<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteVehicleColorPrediction;
use App\Jobs\RunVehicleColorPrediction;
use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleColorPredictionReview;
use App\Models\VehicleColorPredictionRun;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\VehicleColor\SanitizedVehicleColorImage;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleColor\VehicleColorImageSanitizer;
use App\Support\Intelligence\VehicleColor\VehicleColorModelArtifact;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleColorAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(IntelligencePrivateStorage::DISK);
        $this->seed(RolesPermissionsSeeder::class);
        config([
            'intelligence.vehicle_color_v8.enabled' => false,
            'intelligence.vehicle_color_v8.disk' => IntelligencePrivateStorage::DISK,
            'intelligence.vehicle_color_v8.python_binary' => 'python',
            'intelligence.vehicle_color_v8.execution_provider' => 'CPUExecutionProvider',
            'intelligence.vehicle_color_v8.runtime_script' => base_path(
                'scripts/intelligence/color_v8/run_color_v8_onnx.py',
            ),
            'intelligence.vehicle_color_v8.model_path' => '/private/models/S7_COLOR_V8_FINAL.onnx',
            'intelligence.vehicle_color_v8.metadata_path' => '/private/models/S7_COLOR_V8_FINAL_METADATA.json',
        ]);
    }

    public function test_authorized_create_form_shows_a_simple_optional_assistant(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();

        $response = $this->actingAs($fixture['user'])
            ->get(route('vehicles.create'))
            ->assertOk()
            ->assertSee('Photo du véhicule')
            ->assertSee('Analyser la couleur')
            ->assertSee('La valeur finale reste toujours modifiable.')
            ->assertSee('name="color"', false)
            ->assertSee('name="color_prediction_run"', false);

        foreach (['runtime', 'artefact', 'ONNX', 'gate scientifique', 'pipeline'] as $jargon) {
            $response->assertDontSee($jargon, false);
        }
    }

    public function test_disabled_feature_or_missing_vehicle_permission_never_blocks_manual_creation(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['user'])
            ->get(route('vehicles.create'))
            ->assertOk()
            ->assertDontSee('Analyser la couleur')
            ->assertSee('name="color"', false);

        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $this->actingAs($viewer)->get(route('vehicles.create'))->assertForbidden();
        $this->actingAs($viewer)
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.color-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'image' => $this->image(),
            ])->assertForbidden();

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), $this->vehicleData($fixture, 'MANUAL-001', 'Gris'))
            ->assertRedirect();
        $this->assertDatabaseHas('vehicles', [
            'registration_number' => 'MANUAL-001',
            'color' => 'Gris',
        ]);
    }

    public function test_valid_upload_creates_only_a_private_preparatory_run_and_job(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();

        $response = $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.color-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'image' => $this->image(),
            ])->assertStatus(202)
            ->assertJsonStructure(['run_id', 'status', 'status_url'])
            ->assertJson(['status' => 'queued']);

        $this->assertSame(['run_id', 'status', 'status_url'], array_keys($response->json()));
        $run = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($run->vehicle_id);
        $this->assertSame($fixture['agency']->id, $run->agency_id);
        $this->assertSame($fixture['user']->id, $run->requested_by);
        $this->assertSame(0, Vehicle::withoutGlobalScopes()->count());
        Storage::disk(IntelligencePrivateStorage::DISK)->assertExists($run->input_stored_path);
        $this->assertFalse(Storage::disk('public')->exists($run->input_stored_path));
        Queue::assertPushed(
            RunVehicleColorPrediction::class,
            fn (RunVehicleColorPrediction $job): bool => $job->runId === $run->run_id
                && $job->queue === 'intelligence',
        );
    }

    public function test_invalid_upload_and_anonymous_access_are_refused_without_side_effect(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();

        $this->withHeader('Accept', 'application/json')
            ->post(route('vehicles.color-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'image' => $this->image(),
            ])->assertUnauthorized();

        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.color-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'image' => UploadedFile::fake()->createWithContent('vehicle.svg', '<svg/>'),
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertSame(0, VehicleColorPredictionRun::withoutGlobalScopes()->count());
        $this->assertSame(0, Vehicle::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_status_is_requester_scoped_sanitized_and_returns_low_confidence_candidate(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedPreparation($fixture, false, 0.74);

        Auth::logout();
        $this->get(route('vehicles.color-assistant.show', $run))->assertUnauthorized();

        $response = $this->actingAs($fixture['user'])
            ->getJson(route('vehicles.color-assistant.show', $run))
            ->assertOk()
            ->assertExactJson([
                'status' => 'succeeded',
                'suggested_color' => ['value' => 'black', 'label' => 'Noir'],
                'confidence' => 0.74,
                'message' => 'Vérification visuelle recommandée.',
            ]);
        $serialized = $response->getContent();
        foreach (['input_stored_path', 'sha256', 'runtime', 'queue', 'artifact', 'probabilities'] as $privateKey) {
            $this->assertStringNotContainsString($privateKey, $serialized);
        }

        $otherUser = $this->user($fixture, 'tenant-owner', null);
        $this->actingAs($otherUser)
            ->getJson(route('vehicles.color-assistant.show', $run))
            ->assertForbidden();

        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(),
        );
        $agencyManager = $this->user($fixture, 'agency-manager', $otherAgency);
        $this->actingAs($agencyManager)
            ->getJson(route('vehicles.color-assistant.show', $run))
            ->assertForbidden();

        $foreign = $this->fixture();
        $this->actingAs($foreign['user'])
            ->getJson(route('vehicles.color-assistant.show', $run))
            ->assertNotFound();
    }

    public function test_manual_color_is_final_and_successful_run_is_linked_atomically(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedPreparation($fixture, false, 0.74);
        $modelAccepted = $run->model_accepted;

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'ASSIST-001', 'Bleu personnalisé'),
                'color_prediction_run' => $run->run_id,
            ])->assertRedirect();

        $vehicle = Vehicle::withoutGlobalScopes()
            ->where('registration_number', 'ASSIST-001')
            ->firstOrFail();
        $linked = VehicleColorPredictionRun::withoutGlobalScopes()->findOrFail($run->id);
        $this->assertSame('Bleu personnalisé', $vehicle->color);
        $this->assertSame($vehicle->id, $linked->vehicle_id);
        $this->assertSame($modelAccepted, $linked->model_accepted);
        $this->assertSame(0, VehicleColorPredictionReview::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.vehicle_color.preparation_linked',
            'auditable_id' => $run->id,
        ]);
    }

    public function test_suggested_label_can_be_saved_without_becoming_an_automatic_decision(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedPreparation($fixture, true, 0.98);

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'ASSIST-002', 'Noir'),
                'color_prediction_run' => $run->run_id,
            ])->assertRedirect();

        $vehicle = Vehicle::withoutGlobalScopes()
            ->where('registration_number', 'ASSIST-002')
            ->firstOrFail();
        $this->assertSame('Noir', $vehicle->color);
        $this->assertSame(
            $vehicle->id,
            VehicleColorPredictionRun::withoutGlobalScopes()->findOrFail($run->id)->vehicle_id,
        );
        $this->assertSame(0, VehicleColorPredictionReview::withoutGlobalScopes()->count());
    }

    public function test_failed_or_foreign_run_is_refused_but_manual_retry_stays_available(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $failed = $this->queuedPreparation($fixture);
        DB::table('vehicle_color_prediction_runs')->where('id', $failed->id)->update([
            'status' => 'failed',
            'failure_code' => 'INTERNAL_FAILURE',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'FAILED-REFUSED', 'Rouge'),
                'color_prediction_run' => $failed->run_id,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('color_prediction_run');
        $this->assertDatabaseMissing('vehicles', ['registration_number' => 'FAILED-REFUSED']);

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), $this->vehicleData($fixture, 'FAILED-MANUAL', 'Rouge'))
            ->assertRedirect();
        $this->assertDatabaseHas('vehicles', [
            'registration_number' => 'FAILED-MANUAL',
            'color' => 'Rouge',
        ]);

        $otherUser = $this->user($fixture, 'tenant-owner', null);
        $ownedByFirstUser = $this->completedPreparation($fixture, true, 0.98);
        $this->actingAs($otherUser)
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'OTHER-USER-REFUSED', 'Noir'),
                'color_prediction_run' => $ownedByFirstUser->run_id,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('color_prediction_run');
        $this->assertDatabaseMissing('vehicles', ['registration_number' => 'OTHER-USER-REFUSED']);
    }

    public function test_cross_agency_run_and_second_attachment_are_refused_by_server_and_database(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedPreparation($fixture, true, 0.98);
        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(),
        );

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'OTHER-AGENCY-REFUSED', 'Noir'),
                'agency_id' => $otherAgency->id,
                'color_prediction_run' => $run->run_id,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('color_prediction_run');
        $this->assertDatabaseMissing('vehicles', ['registration_number' => 'OTHER-AGENCY-REFUSED']);

        $this->actingAs($fixture['user'])
            ->post(route('vehicles.store'), [
                ...$this->vehicleData($fixture, 'FIRST-LINK', 'Noir'),
                'color_prediction_run' => $run->run_id,
            ])->assertRedirect();
        $secondVehicle = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Vehicle::create([
                ...$this->vehicleData($fixture, 'SECOND-LINK', 'Noir'),
                'operational_status' => 'active',
            ]),
        );

        try {
            DB::table('vehicle_color_prediction_runs')
                ->where('id', $run->id)
                ->update(['vehicle_id' => $secondVehicle->id]);
            $this->fail('Le rattachement immuable devait être refusé.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }

    public function test_vehicle_creation_permission_is_sufficient_for_preparation_without_prediction_admin_rights(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $role = $fixture['user']->role()->firstOrFail();
        $permissionIds = Permission::query()
            ->whereIn('slug', ['prediction.view', 'prediction.color.review'])
            ->pluck('id');
        $role->permissions()->detach($permissionIds);
        $fixture['user']->unsetRelation('role');

        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.color-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'image' => $this->image(),
            ])->assertStatus(202);

        $run = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        Process::fake([
            '*' => Process::result(output: $this->resultJson($run, true, 0.98)),
        ]);
        (new RunVehicleColorPrediction($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteVehicleColorPrediction::class));
    }

    private function queuedPreparation(array $fixture): VehicleColorPredictionRun
    {
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->withHeader('Accept', 'application/json')
            ->post(route('vehicles.color-assistant.store'), [
                'agency_id' => $fixture['agency']->id,
                'image' => $this->image(),
            ])->assertStatus(202);

        return VehicleColorPredictionRun::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function completedPreparation(
        array $fixture,
        bool $accepted,
        float $confidence,
    ): VehicleColorPredictionRun {
        $this->enableRuntime();
        $run = $this->queuedPreparation($fixture);
        Process::fake(['*' => Process::result(output: $this->resultJson($run, $accepted, $confidence))]);
        (new RunVehicleColorPrediction($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteVehicleColorPrediction::class));

        return VehicleColorPredictionRun::withoutGlobalScopes()->findOrFail($run->id);
    }

    private function enableRuntime(): void
    {
        config(['intelligence.vehicle_color_v8.enabled' => true]);
        $artifact = $this->mock(VehicleColorModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturnTrue();
        $artifact->shouldReceive('configuredModelPath')->andReturn('/private/models/color.onnx');
        $artifact->shouldReceive('configuredMetadataPath')->andReturn('/private/models/color.json');
        $contents = $this->imageBytes();
        $sanitizer = $this->mock(VehicleColorImageSanitizer::class);
        $sanitizer->shouldReceive('sanitize')->andReturnUsing(
            static fn () => new SanitizedVehicleColorImage(
                contents: $contents,
                mime: 'image/png',
                extension: 'png',
                bytes: strlen($contents),
                sha256: hash('sha256', $contents),
                width: 1,
                height: 1,
            ),
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
                'code' => 'COLOR-'.str_pad((string) $tenant->id, 3, '0', STR_PAD_LEFT),
                'name' => 'Catégorie couleur',
                'is_active' => true,
            ]),
        );

        return compact('tenant', 'agency', 'user', 'category');
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

    private function vehicleData(array $fixture, string $registration, string $color): array
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
            'color' => $color,
            'current_mileage' => 1000,
        ];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('vehicle.png', $this->imageBytes());
    }

    private function imageBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        return is_string($bytes) ? $bytes : throw new \LogicException('Invalid PNG fixture.');
    }

    private function resultJson(
        VehicleColorPredictionRun $run,
        bool $accepted,
        float $confidence,
    ): string {
        $remaining = (1 - $confidence) / (count(VehicleColorContract::CLASSES) - 1);
        $probabilities = array_fill_keys(VehicleColorContract::CLASSES, $remaining);
        $probabilities['black'] = $confidence;

        return json_encode([
            'schema_version' => VehicleColorContract::RESULT_SCHEMA_VERSION,
            'model' => [
                'name' => VehicleColorContract::MODEL_NAME,
                'version' => VehicleColorContract::MODEL_VERSION,
                'artifact_sha256' => VehicleColorContract::MODEL_ARTIFACT_SHA256,
                'metadata_sha256' => VehicleColorContract::METADATA_SHA256,
            ],
            'input' => [
                'sha256' => $run->input_sha256,
                'bytes' => $run->input_bytes,
                'mime' => $run->input_mime,
            ],
            'result' => [
                'suggested_color' => 'black',
                'confidence' => $confidence,
                'accepted' => $accepted,
                'top_class_index' => 0,
                'top_class' => 'black',
                'probabilities' => $probabilities,
            ],
            'safety' => [
                'mode' => VehicleColorContract::MODE,
                'human_validation_required' => true,
                'automatic_business_action_allowed' => false,
                'operational_effect' => VehicleColorContract::OPERATIONAL_EFFECT,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
