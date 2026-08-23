<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteVehicleColorPrediction;
use App\Actions\Intelligence\QueueVehicleColorPrediction;
use App\Actions\Intelligence\RecordVehicleColorPredictionReview;
use App\Enums\VehicleColorPredictionStatus;
use App\Enums\VehicleColorReviewDecision;
use App\Exceptions\VehicleColorExecutionException;
use App\Exceptions\VehicleColorRuntimeUnavailableException;
use App\Jobs\RunVehicleColorPrediction;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleColorPredictionReview;
use App\Models\VehicleColorPredictionRun;
use App\Support\Intelligence\VehicleColor\SanitizedVehicleColorImage;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleColor\VehicleColorImageSanitizer;
use App\Support\Intelligence\VehicleColor\VehicleColorInputArtifact;
use App\Support\Intelligence\VehicleColor\VehicleColorModelArtifact;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleColorPredictionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
        config([
            'intelligence.vehicle_color_v8.enabled' => false,
            'intelligence.vehicle_color_v8.disk' => 'local',
            'intelligence.vehicle_color_v8.python_binary' => 'python',
            'intelligence.vehicle_color_v8.execution_provider' => 'CPUExecutionProvider',
            'intelligence.vehicle_color_v8.runtime_script' => base_path(
                'scripts/intelligence/color_v8/run_color_v8_onnx.py',
            ),
            'intelligence.vehicle_color_v8.model_path' => '/private/models/S7_COLOR_V8_FINAL.onnx',
            'intelligence.vehicle_color_v8.metadata_path' => '/private/models/S7_COLOR_V8_FINAL_METADATA.json',
        ]);
    }

    public function test_feature_is_disabled_by_default_and_cannot_create_a_run(): void
    {
        $fixture = $this->fixture();
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertForbidden();

        $this->assertSame(0, VehicleColorPredictionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-colors.index'))
            ->assertOk()
            ->assertSee('Désactivé par défaut')
            ->assertSee('Aucune action automatique');
    }

    public function test_upload_rate_limit_is_segmented_by_actor_and_capped_by_scope(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        config([
            'intelligence.vehicle_color_v8.rate_limits.user_per_minute' => 2,
            'intelligence.vehicle_color_v8.rate_limits.scope_per_hour' => 3,
        ]);

        $submitInvalidImage = function (User $user) use ($fixture) {
            return $this->actingAs($user)->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => UploadedFile::fake()->createWithContent('vehicle.svg', '<svg></svg>'),
            ]);
        };

        $submitInvalidImage($fixture['user'])->assertSessionHasErrors('image');
        $submitInvalidImage($fixture['user'])->assertSessionHasErrors('image');
        $submitInvalidImage($fixture['user'])->assertStatus(429);

        $secondUser = $this->user($fixture, 'tenant-owner', null);
        $submitInvalidImage($secondUser)->assertSessionHasErrors('image');
        $submitInvalidImage($secondUser)->assertStatus(429);

        $this->assertSame(0, VehicleColorPredictionRun::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/color-v8'));
        Queue::assertNothingPushed();
    }

    public function test_authorized_run_is_private_queued_executed_and_never_updates_vehicle_color(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $beforeColor = $fixture['vehicle']->color;

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertRedirect(route('intelligence.vehicle-colors.index'))
            ->assertSessionHas('status');

        $run = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehicleColorPredictionStatus::Queued, $run->status);
        $this->assertSame(VehicleColorContract::MODEL_ARTIFACT_SHA256, $run->model_artifact_sha256);
        $this->assertSame(VehicleColorContract::METADATA_SHA256, $run->metadata_sha256);
        $this->assertSame(VehicleColorContract::OPERATIONAL_EFFECT, $run->operational_effect);
        $this->assertSame(
            'intelligence/color-v8/inputs/'
                .$run->tenant_id.'/'
                .$run->run_id.'.'
                .$run->input_extension,
            $run->input_stored_path,
        );
        Storage::disk('local')->assertExists($run->input_stored_path);
        Queue::assertPushed(
            RunVehicleColorPrediction::class,
            fn (RunVehicleColorPrediction $job): bool => $job->runId === $run->run_id
                && $job->tenantId === $fixture['tenant']->id
                && $job->actorId === $fixture['user']->id
                && $job->queue === 'intelligence',
        );

        Process::fake(['*' => Process::result(output: $this->resultJson($run, true))]);
        $job = new RunVehicleColorPrediction($run->run_id, $run->tenant_id, $run->requested_by);
        $job->handle(app(ExecuteVehicleColorPrediction::class));

        $completed = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehicleColorPredictionStatus::Succeeded, $completed->status);
        $this->assertSame('black', $completed->suggested_color);
        $this->assertSame('0.9800000', $completed->confidence);
        $this->assertTrue($completed->model_accepted);
        $this->assertNotNull($completed->started_at);
        $this->assertNotNull($completed->finished_at);
        $this->assertSame(
            $beforeColor,
            Vehicle::withoutGlobalScopes()->findOrFail($fixture['vehicle']->id)->color,
        );
        Process::assertRan(fn ($process): bool => is_array($process->command)
            && $process->command[0] === 'python'
            && $process->command[1] === config('intelligence.vehicle_color_v8.runtime_script')
            && in_array('--stdout', $process->command, true)
            && in_array('CPUExecutionProvider', $process->command, true)
            && in_array('/private/models/S7_COLOR_V8_FINAL.onnx', $process->command, true)
            && $process->timeout === 30);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_color.run_queued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_color.run_succeeded']);

        $page = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-colors.index'))
            ->assertOk()
            ->assertSee('Noir')
            ->assertSee('Acceptable pour revue humaine')
            ->assertDontSee($completed->input_stored_path)
            ->assertDontSee($completed->input_sha256)
            ->assertDontSee($completed->model_artifact_sha256);
        $this->assertStringNotContainsString('/private/models/', $page->getContent());

        $input = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-colors.input', $completed))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->assertEqualsCanonicalizing(
            ['private', 'no-store', 'max-age=0'],
            array_map('trim', explode(',', (string) $input->headers->get('cache-control'))),
        );
        $this->assertSame($this->imageBytes(), $input->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_color.input_viewed']);
    }

    public function test_only_reencoded_metadata_free_image_is_stored_and_identified(): void
    {
        $fixture = $this->fixture();
        $sanitizedBytes = $this->imageBytes();
        $sourceBytes = $sanitizedBytes.'private-exif-gps-marker';
        $this->enableRuntime($sanitizedBytes);
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => UploadedFile::fake()->createWithContent('vehicle.png', $sourceBytes),
            ])
            ->assertRedirect();

        $run = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        $stored = Storage::disk('local')->get($run->input_stored_path);
        $this->assertSame($sanitizedBytes, $stored);
        $this->assertStringNotContainsString('private-exif-gps-marker', $stored);
        $this->assertSame(strlen($sanitizedBytes), $run->input_bytes);
        $this->assertSame(hash('sha256', $sanitizedBytes), $run->input_sha256);
        $this->assertNotSame(hash('sha256', $sourceBytes), $run->input_sha256);
        Queue::assertPushed(RunVehicleColorPrediction::class);
    }

    public function test_image_sanitization_failure_closes_before_storage_database_and_queue(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $sanitizer = $this->mock(VehicleColorImageSanitizer::class);
        $sanitizer->shouldReceive('sanitize')
            ->once()
            ->andThrow(new VehicleColorRuntimeUnavailableException);
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertServiceUnavailable();

        $this->assertSame(0, VehicleColorPredictionRun::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/color-v8'));
        Queue::assertNothingPushed();
    }

    public function test_php_python_sanitizer_bridge_removes_exif_gps_and_applies_orientation(): void
    {
        $python = getenv('COLOR_V8_TEST_PYTHON_BINARY');
        if (! is_string($python) || ! is_file($python)) {
            $this->markTestSkipped('Le runtime Python figé du sanitizer est réservé à la CI.');
        }
        config([
            'intelligence.vehicle_color_v8.python_binary' => $python,
            'intelligence.vehicle_color_v8.image_sanitizer_script' => base_path(
                'scripts/intelligence/color_v8/sanitize_vehicle_image.py',
            ),
        ]);
        $source = $this->metadataJpegBytes();
        $this->assertStringContainsString('private-customer-location', $source);

        $sanitized = app(VehicleColorImageSanitizer::class)->sanitize(
            UploadedFile::fake()->createWithContent('vehicle.jpg', $source),
        );

        $this->assertSame('image/jpeg', $sanitized->mime);
        $this->assertSame('jpg', $sanitized->extension);
        $this->assertSame(20, $sanitized->width);
        $this->assertSame(40, $sanitized->height);
        $this->assertSame(strlen($sanitized->contents), $sanitized->bytes);
        $this->assertSame(hash('sha256', $sanitized->contents), $sanitized->sha256);
        $this->assertStringNotContainsString('private-customer-location', $sanitized->contents);
        $this->assertNotSame(hash('sha256', $source), $sanitized->sha256);
    }

    public function test_human_acceptance_is_append_only_and_has_no_operational_effect(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, true);
        $before = Vehicle::withoutGlobalScopes()->findOrFail($fixture['vehicle']->id)->color;

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.reviews.store', $run), [
                'decision' => VehicleColorReviewDecision::Accepted->value,
                'note' => 'Couleur confirmée visuellement.',
            ])
            ->assertRedirect(route('intelligence.vehicle-colors.index'))
            ->assertSessionHas('status');

        $review = VehicleColorPredictionReview::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehicleColorReviewDecision::Accepted, $review->decision);
        $this->assertSame(VehicleColorContract::OPERATIONAL_EFFECT, $review->effect);
        $this->assertSame(
            $before,
            Vehicle::withoutGlobalScopes()->findOrFail($fixture['vehicle']->id)->color,
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.vehicle_color.human_decision_recorded',
        ]);

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.reviews.store', $run), [
                'decision' => VehicleColorReviewDecision::Rejected->value,
            ])
            ->assertStatus(409);
        $this->assertSame(1, VehicleColorPredictionReview::withoutGlobalScopes()->count());
        $this->assertPostgreSqlConstraint(fn () => DB::table('vehicle_color_prediction_reviews')
            ->where('id', $review->id)
            ->update(['decision' => 'rejected']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('vehicle_color_prediction_runs')
            ->where('id', $run->id)
            ->update(['suggested_color' => 'red']));
    }

    public function test_abstention_cannot_be_human_accepted_but_can_be_rejected(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, false);
        $this->assertFalse($run->model_accepted);

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-colors.index'))
            ->assertOk()
            ->assertSee('Abstention du modèle')
            ->assertSee('Abstention obligatoire')
            ->assertDontSee('Accepter la suggestion');
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.reviews.store', $run), [
                'decision' => VehicleColorReviewDecision::Accepted->value,
            ])
            ->assertSessionHasErrors('decision');
        $this->assertSame(0, VehicleColorPredictionReview::withoutGlobalScopes()->count());

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.reviews.store', $run), [
                'decision' => VehicleColorReviewDecision::Rejected->value,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('vehicle_color_prediction_reviews', [
            'decision' => VehicleColorReviewDecision::Rejected->value,
            'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
        ]);
    }

    public function test_bad_input_and_unverified_artifact_fail_closed_before_queueing(): void
    {
        $fixture = $this->fixture();
        config(['intelligence.vehicle_color_v8.enabled' => true]);
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => UploadedFile::fake()->createWithContent('vehicle.svg', '<svg></svg>'),
            ])
            ->assertSessionHasErrors('image');
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
                'tenant_id' => $fixture['tenant']->id,
                'model_path' => '/tmp/untrusted.onnx',
            ])
            ->assertSessionHasErrors(['tenant_id', 'model_path']);
        $this->assertSame(0, VehicleColorPredictionRun::withoutGlobalScopes()->count());

        $artifact = $this->mock(VehicleColorModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturnFalse();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertServiceUnavailable();
        $this->assertSame(0, VehicleColorPredictionRun::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/color-v8'));
        Queue::assertNothingPushed();
    }

    public function test_tampered_runtime_output_is_sanitized_and_persisted_as_failure(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertRedirect();
        $run = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        $payload = json_decode($this->resultJson($run, true), true, flags: JSON_THROW_ON_ERROR);
        $payload['model']['artifact_sha256'] = str_repeat('0', 64);
        $payload['secret_path'] = '/private/models/forbidden.onnx';
        Process::fake(['*' => Process::result(output: json_encode($payload, JSON_THROW_ON_ERROR))]);
        $job = new RunVehicleColorPrediction($run->run_id, $run->tenant_id, $run->requested_by);

        $failure = null;
        try {
            $job->handle(app(ExecuteVehicleColorPrediction::class));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertNotNull($failure);
        $job->failed($failure);

        $failed = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehicleColorPredictionStatus::Failed, $failed->status);
        $this->assertSame('COLOR_OUTPUT_CONTRACT_INVALID', $failed->failure_code);
        $this->assertNull($failed->suggested_color);
        $this->assertFalse(AuditLog::withoutGlobalScopes()->get()->contains(
            static fn (AuditLog $audit): bool => str_contains(
                json_encode($audit->new_values, JSON_THROW_ON_ERROR),
                '/private/models/forbidden.onnx',
            ),
        ));
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-colors.index'))
            ->assertOk()
            ->assertSee('La sortie ONNX ne respecte pas le contrat fermé.')
            ->assertDontSee('/private/models/forbidden.onnx');
    }

    public function test_only_one_active_run_exists_per_vehicle_and_stale_runs_are_closed(): void
    {
        CarbonImmutable::setTestNow('2026-08-22 10:00:00+01:00');
        try {
            $fixture = $this->fixture();
            $this->enableRuntime();
            Queue::fake();
            $request = fn () => $this->actingAs($fixture['user'])
                ->post(route('intelligence.vehicle-colors.store'), [
                    'vehicle_id' => $fixture['vehicle']->id,
                    'image' => $this->image(),
                ]);

            $request()->assertRedirect();
            $request()->assertStatus(409);
            $this->assertSame(1, VehicleColorPredictionRun::withoutGlobalScopes()->count());
            $this->assertSame(1, Queue::pushed(RunVehicleColorPrediction::class)->count());

            CarbonImmutable::setTestNow('2026-08-22 10:11:00+01:00');
            $request()->assertRedirect();
            $runs = VehicleColorPredictionRun::withoutGlobalScopes()->orderBy('id')->get();
            $this->assertCount(2, $runs);
            $this->assertSame(VehicleColorPredictionStatus::Failed, $runs[0]->status);
            $this->assertSame('RUN_STALE_RECOVERED', $runs[0]->failure_code);
            $this->assertSame(VehicleColorPredictionStatus::Queued, $runs[1]->status);
            $this->assertSame(2, Queue::pushed(RunVehicleColorPrediction::class)->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_tenant_agency_permission_and_private_image_boundaries_are_enforced(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $run = $this->completedRun($fixture, true);
        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence couleur B']),
        );
        $otherManager = $this->user($fixture, 'fleet-manager', $otherAgency);
        $foreign = $this->fixture();

        $this->actingAs($viewer)
            ->get(route('intelligence.vehicle-colors.index'))
            ->assertOk();
        $this->actingAs($viewer)
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertForbidden();
        $this->actingAs($otherManager)
            ->get(route('intelligence.vehicle-colors.input', $run))
            ->assertForbidden();
        $this->actingAs($otherManager)
            ->post(route('intelligence.vehicle-colors.reviews.store', $run), [
                'decision' => 'rejected',
            ])
            ->assertForbidden();
        $this->actingAs($foreign['user'])
            ->get(route('intelligence.vehicle-colors.input', $run))
            ->assertNotFound();
        $this->actingAs($foreign['user'])
            ->post(route('intelligence.vehicle-colors.reviews.store', $run), [
                'decision' => 'rejected',
            ])
            ->assertNotFound();

        foreach (['tenant-owner', 'agency-manager', 'fleet-manager'] as $role) {
            $this->assertTrue($this->user(
                $fixture,
                $role,
                $role === 'tenant-owner' ? null : $fixture['agency'],
            )
                ->hasPermission('prediction.color.review'), $role);
        }
        foreach (['rental-agent', 'viewer-auditor'] as $role) {
            $this->assertFalse($this->user($fixture, $role, $fixture['agency'])
                ->hasPermission('prediction.color.review'), $role);
        }
    }

    public function test_color_review_permission_alone_cannot_access_queue_or_review_predictions(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, true);
        $role = Role::query()->forceCreate([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Revue couleur sans consultation',
            'slug' => 'color-review-without-view',
            'is_system' => false,
            'is_active' => true,
            'created_by' => $fixture['user']->id,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('slug', 'prediction.color.review')->value('id'),
        );
        $reviewer = User::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency']->id,
            'role_id' => $role->id,
            'must_change_password' => false,
        ]);
        Queue::fake();

        $this->actingAs($reviewer)
            ->get(route('intelligence.vehicle-colors.index'))
            ->assertForbidden();
        $this->actingAs($reviewer)
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertForbidden();
        $this->actingAs($reviewer)
            ->post(route('intelligence.vehicle-colors.reviews.store', $run), [
                'decision' => VehicleColorReviewDecision::Rejected->value,
            ])
            ->assertForbidden();

        $this->assertSame(1, VehicleColorPredictionRun::withoutGlobalScopes()->count());
        $this->assertSame(0, VehicleColorPredictionReview::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_database_rejects_vehicle_agency_mismatch_in_prediction_scope(): void
    {
        $fixture = $this->fixture();
        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence forgée couleur']),
        );
        $runId = (string) Str::uuid();

        $this->assertPostgreSqlForeignKeyConstraint(
            fn () => DB::table('vehicle_color_prediction_runs')->insert([
                'tenant_id' => $fixture['tenant']->id,
                'agency_id' => $otherAgency->id,
                'run_id' => $runId,
                'vehicle_id' => $fixture['vehicle']->id,
                'requested_by' => $fixture['user']->id,
                'status' => VehicleColorPredictionStatus::Queued->value,
                'failure_code' => null,
                'input_mime' => 'image/png',
                'input_extension' => 'png',
                'input_bytes' => 1,
                'input_sha256' => str_repeat('a', 64),
                'input_stored_path' => 'intelligence/color-v8/inputs/'
                    .$fixture['tenant']->id.'/'.$runId.'.png',
                'suggested_color' => null,
                'confidence' => null,
                'model_accepted' => null,
                'probabilities' => null,
                'model_name' => VehicleColorContract::MODEL_NAME,
                'model_version' => VehicleColorContract::MODEL_VERSION,
                'model_artifact_sha256' => VehicleColorContract::MODEL_ARTIFACT_SHA256,
                'metadata_sha256' => VehicleColorContract::METADATA_SHA256,
                'accepted_threshold' => VehicleColorContract::ACCEPTED_THRESHOLD,
                'operational_effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                'requested_at' => now(),
                'started_at' => null,
                'finished_at' => null,
            ]),
        );
    }

    public function test_database_and_runtime_bind_private_input_to_tenant_and_run(): void
    {
        $fixture = $this->fixture();
        $runId = (string) Str::uuid();
        $bytes = $this->imageBytes();
        $foreignTenantPath = 'intelligence/color-v8/inputs/'
            .($fixture['tenant']->id + 1).'/'.$runId.'.png';
        $row = [
            'tenant_id' => $fixture['tenant']->id,
            'agency_id' => $fixture['agency']->id,
            'run_id' => $runId,
            'vehicle_id' => $fixture['vehicle']->id,
            'requested_by' => $fixture['user']->id,
            'status' => VehicleColorPredictionStatus::Queued->value,
            'failure_code' => null,
            'input_mime' => 'image/png',
            'input_extension' => 'png',
            'input_bytes' => strlen($bytes),
            'input_sha256' => hash('sha256', $bytes),
            'input_stored_path' => $foreignTenantPath,
            'suggested_color' => null,
            'confidence' => null,
            'model_accepted' => null,
            'probabilities' => null,
            'model_name' => VehicleColorContract::MODEL_NAME,
            'model_version' => VehicleColorContract::MODEL_VERSION,
            'model_artifact_sha256' => VehicleColorContract::MODEL_ARTIFACT_SHA256,
            'metadata_sha256' => VehicleColorContract::METADATA_SHA256,
            'accepted_threshold' => VehicleColorContract::ACCEPTED_THRESHOLD,
            'operational_effect' => VehicleColorContract::OPERATIONAL_EFFECT,
            'requested_at' => now(),
            'started_at' => null,
            'finished_at' => null,
        ];

        $this->assertPostgreSqlConstraint(
            fn () => DB::table('vehicle_color_prediction_runs')->insert($row),
        );

        $sameTenantOtherRunPath = 'intelligence/color-v8/inputs/'
            .$fixture['tenant']->id.'/'.Str::uuid().'.png';
        foreach ([$foreignTenantPath, $sameTenantOtherRunPath] as $forgedPath) {
            Storage::disk('local')->put($forgedPath, $bytes);
            $forgedRun = (new VehicleColorPredictionRun)->forceFill([
                ...$row,
                'input_stored_path' => $forgedPath,
            ]);

            $this->assertFalse(app(VehicleColorInputArtifact::class)->valid($forgedRun));
        }
    }

    public function test_internal_review_action_revalidates_view_permission(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, true);
        $this->revokeViewPermission($fixture['user']);

        $failure = null;
        try {
            app(TenantContext::class)->run(
                $fixture['tenant'],
                fn () => app(RecordVehicleColorPredictionReview::class)->handle(
                    $run,
                    $fixture['user'],
                    VehicleColorReviewDecision::Rejected,
                    null,
                ),
                $fixture['agency']->id,
            );
        } catch (AuthorizationException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $failure);
        $this->assertSame(0, VehicleColorPredictionReview::withoutGlobalScopes()->count());
    }

    public function test_internal_queue_action_revalidates_view_permission(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $this->revokeViewPermission($fixture['user']);

        $failure = null;
        try {
            app(TenantContext::class)->run(
                $fixture['tenant'],
                fn () => app(QueueVehicleColorPrediction::class)->handle(
                    $fixture['vehicle'],
                    $this->image(),
                    $fixture['user'],
                ),
                $fixture['agency']->id,
            );
        } catch (AuthorizationException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $failure);
        $this->assertSame(0, VehicleColorPredictionRun::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('intelligence/color-v8'));
        Queue::assertNothingPushed();
    }

    public function test_worker_revalidates_view_permission_after_queueing(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertRedirect();

        $run = VehicleColorPredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->revokeViewPermission($fixture['user']);
        Process::fake();
        $job = new RunVehicleColorPrediction($run->run_id, $run->tenant_id, $run->requested_by);

        $failure = null;
        try {
            $job->handle(app(ExecuteVehicleColorPrediction::class));
        } catch (VehicleColorExecutionException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(VehicleColorExecutionException::class, $failure);
        $this->assertSame('RUN_ACTOR_NOT_AUTHORIZED', $failure->failureCode());
        $job->failed($failure);
        $this->assertDatabaseHas('vehicle_color_prediction_runs', [
            'id' => $run->id,
            'status' => VehicleColorPredictionStatus::Failed->value,
            'failure_code' => 'RUN_ACTOR_NOT_AUTHORIZED',
        ]);
        Process::assertDidntRun(fn ($process, $result): bool => true);
    }

    public function test_vehicle_selector_is_bounded_and_searchable(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        $now = now();
        $vehicles = [];
        for ($index = 2; $index <= 60; $index++) {
            $vehicles[] = [
                'tenant_id' => $fixture['tenant']->id,
                'agency_id' => $fixture['agency']->id,
                'vehicle_category_id' => $fixture['vehicle']->vehicle_category_id,
                'registration_number' => sprintf('RF-COLOR-%03d', $index),
                'brand' => $index === 60 ? 'MarqueCible' : 'RentFleet',
                'model' => $index === 60 ? 'ModeleUnique' : 'Sélecteur',
                'fuel_type' => 'petrol',
                'transmission' => 'automatic',
                'current_mileage' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Vehicle::withoutGlobalScopes()->insert($vehicles);

        $response = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-colors.index'))
            ->assertOk()
            ->assertSee('Le sélecteur affiche au maximum 50 véhicules.');
        $listed = $response->viewData('vehicles');
        $this->assertCount(50, $listed);
        $this->assertSame('RF-COLOR-001', $listed->first()->registration_number);
        $this->assertSame('RF-COLOR-050', $listed->last()->registration_number);

        $searched = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-colors.index', ['vehicle_search' => 'marquecible']))
            ->assertOk()
            ->assertSee('RF-COLOR-060')
            ->viewData('vehicles');
        $this->assertCount(1, $searched);
        $this->assertSame('RF-COLOR-060', $searched->first()->registration_number);
    }

    public function test_schema_routes_permission_and_installer_contract_are_present(): void
    {
        foreach ([
            'intelligence.vehicle-colors.index',
            'intelligence.vehicle-colors.store',
            'intelligence.vehicle-colors.input',
            'intelligence.vehicle-colors.reviews.store',
        ] as $route) {
            $this->assertTrue(app('router')->has($route), $route);
        }
        foreach (['vehicle_color_prediction_runs', 'vehicle_color_prediction_reviews'] as $table) {
            $this->assertTrue(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists(), $table);
        }
        $this->assertDatabaseHas('permissions', [
            'slug' => 'prediction.color.review',
            'group' => 'prediction',
        ]);

        $invalidDirectory = Storage::disk('local')->path('invalid-color-bundle');
        mkdir($invalidDirectory, 0700, true);
        $this->artisan('rentfleet:color-v8:install', ['source' => $invalidDirectory])
            ->assertFailed();
        $this->assertFileDoesNotExist(config('intelligence.vehicle_color_v8.model_path'));
    }

    private function completedRun(array $fixture, bool $accepted): VehicleColorPredictionRun
    {
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-colors.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->image(),
            ])
            ->assertRedirect();
        $run = VehicleColorPredictionRun::withoutGlobalScopes()->latest('id')->firstOrFail();
        Process::fake(['*' => Process::result(output: $this->resultJson($run, $accepted))]);
        (new RunVehicleColorPrediction($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteVehicleColorPrediction::class));

        return VehicleColorPredictionRun::withoutGlobalScopes()->findOrFail($run->id);
    }

    private function revokeViewPermission(User $user): void
    {
        $permission = Permission::query()
            ->where('slug', 'prediction.view')
            ->firstOrFail();
        $user->role()->firstOrFail()->permissions()->detach($permission->id);
        $user->unsetRelation('role');
    }

    private function enableRuntime(?string $sanitizedBytes = null): void
    {
        config(['intelligence.vehicle_color_v8.enabled' => true]);
        $artifact = $this->mock(VehicleColorModelArtifact::class);
        $artifact->shouldReceive('configuredIsValid')->andReturnTrue();
        $artifact->shouldReceive('configuredModelPath')->andReturn('/private/models/S7_COLOR_V8_FINAL.onnx');
        $artifact->shouldReceive('configuredMetadataPath')->andReturn('/private/models/S7_COLOR_V8_FINAL_METADATA.json');
        $contents = $sanitizedBytes ?? $this->imageBytes();
        $sanitizer = $this->mock(VehicleColorImageSanitizer::class);
        $sanitizer->shouldReceive('sanitize')
            ->andReturnUsing(static function () use ($contents): SanitizedVehicleColorImage {
                return new SanitizedVehicleColorImage(
                    contents: $contents,
                    mime: 'image/png',
                    extension: 'png',
                    bytes: strlen($contents),
                    sha256: hash('sha256', $contents),
                    width: 1,
                    height: 1,
                );
            });
    }

    private function fixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Entreprise test couleur']);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence test couleur']),
        );
        $user = $this->user(
            compact('tenant', 'agency'),
            $roleSlug,
            $roleSlug === 'tenant-owner' ? null : $agency,
        );
        $vehicle = app(TenantContext::class)->run($tenant, function () use ($agency): Vehicle {
            $category = VehicleCategory::create([
                'code' => 'COLOR',
                'name' => 'Catégorie couleur',
                'is_active' => true,
            ]);

            return Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'RF-COLOR-001',
                'vin' => 'COLORV8VIN0000001',
                'brand' => 'RentFleet',
                'model' => 'Color Test',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'automatic',
                'color' => 'Bleu saisi manuellement',
                'current_mileage' => 1000,
            ]);
        }, $agency->id);

        return compact('tenant', 'agency', 'user', 'vehicle');
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

    private function image(string $name = 'vehicle.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->imageBytes());
    }

    private function imageBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if (! is_string($bytes)) {
            throw new \LogicException('La fixture PNG de test est invalide.');
        }

        return $bytes;
    }

    private function metadataJpegBytes(): string
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/4QC6RXhpZgAATU0AKgAAAAgAAwEOAAIAAAAaAAAAMgESAAMAAAABAAYAAIglAAQAAAABAAAATAAAAABwcml2YXRlLWN1c3RvbWVyLWxvY2F0aW9uAAAEAAEAAgAAAAJOAAAAAAIABQAAAAMAAACCAAMAAgAAAAJXAAAAAAQABQAAAAMAAACaAAAAAAAAACEAAAABAAAAIwAAAAEAAAAAAAAAAQAAAAcAAAABAAAAJAAAAAEAAAAAAAAAAf/bAEMAAwICAwICAwMDAwQDAwQFCAUFBAQFCgcHBggMCgwMCwoLCw0OEhANDhEOCwsQFhARExQVFRUMDxcYFhQYEhQVFP/bAEMBAwQEBQQFCQUFCRQNCw0UFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFP/AABEIABQAKAMBIgACEQEDEQH/xAAfAAABBQEBAQEBAQAAAAAAAAAAAQIDBAUGBwgJCgv/xAC1EAACAQMDAgQDBQUEBAAAAX0BAgMABBEFEiExQQYTUWEHInEUMoGRoQgjQrHBFVLR8CQzYnKCCQoWFxgZGiUmJygpKjQ1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4eLj5OXm5+jp6vHy8/T19vf4+fr/xAAfAQADAQEBAQEBAQEAAAAAAAAAAQIDBAUGBwgJCgv/xAC1EQACAQIEBAMEBwUEBAABAncAAQIDEQQFITEGEkFRB2FxEyIygQgUQpGhscEJIzNS8BVictEKFiQ04SXxFxgZGiYnKCkqNTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqCg4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2dri4+Tl5ufo6ery8/T19vf4+fr/2gAMAwEAAhEDEQA/APmyiiivyI/0YCiiigAooooAKKKKACiiigAooooA/9k=',
            true,
        );
        if (! is_string($bytes)) {
            throw new \LogicException('La fixture JPEG EXIF/GPS de test est invalide.');
        }

        return $bytes;
    }

    private function resultJson(VehicleColorPredictionRun $run, bool $accepted): string
    {
        $probabilities = $accepted
            ? [
                'black' => 0.98,
                'blue' => 0.003,
                'gray' => 0.003,
                'green' => 0.003,
                'orange' => 0.002,
                'red' => 0.002,
                'white' => 0.002,
                'yellow' => 0.002,
                '__reject__' => 0.003,
            ]
            : [
                'black' => 0.50,
                'blue' => 0.05,
                'gray' => 0.05,
                'green' => 0.05,
                'orange' => 0.05,
                'red' => 0.05,
                'white' => 0.05,
                'yellow' => 0.05,
                '__reject__' => 0.15,
            ];

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
                'confidence' => $probabilities['black'],
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

    private function assertPostgreSqlForeignKeyConstraint(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Une contrainte de périmètre PostgreSQL devait refuser cette mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', (string) $exception->getCode());
        }
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
