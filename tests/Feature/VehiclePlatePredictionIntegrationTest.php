<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteVehiclePlatePrediction;
use App\Enums\VehiclePlatePredictionStatus;
use App\Enums\VehiclePlateReviewDecision;
use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Jobs\RunVehiclePlatePrediction;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehiclePlatePredictionReview;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Intelligence\VehiclePlate\SanitizedVehiclePlateImage;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridRuntime;
use App\Support\Intelligence\VehiclePlate\VehiclePlateImageSanitizer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehiclePlatePredictionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
        config([
            'intelligence.vehicle_plate_hybrid_review.enabled' => false,
            'intelligence.vehicle_plate_hybrid_review.disk' => 'local',
            'intelligence.vehicle_plate_hybrid_review.python_binary' => 'python',
            'intelligence.vehicle_plate_hybrid_review.device' => 'cpu',
            'intelligence.vehicle_plate_hybrid_review.runtime_timeout_seconds' => 120,
            'intelligence.vehicle_plate_hybrid_review.runtime_script' => base_path(
                'scripts/intelligence/vehicle_plate/hybrid_ocr_worker.py',
            ),
            'intelligence.vehicle_plate_hybrid_review.image_sanitizer_script' => base_path(
                'scripts/intelligence/color_v8/sanitize_vehicle_image.py',
            ),
        ]);
    }

    public function test_feature_is_disabled_by_default_and_cannot_queue_a_crop(): void
    {
        $fixture = $this->fixture();
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->upload(),
            ])
            ->assertForbidden();

        $this->assertSame(0, VehiclePlatePredictionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-plates.index'))
            ->assertOk()
            ->assertSee('Désactivé par défaut')
            ->assertSee('Aucune action automatique')
            ->assertSee('1 783 lignes restantes');
    }

    public function test_private_crop_is_queued_executed_and_never_updates_vehicle_registration(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $before = $fixture['vehicle']->registration_number;

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->upload(),
            ])
            ->assertRedirect(route('intelligence.vehicle-plates.index'));

        $run = VehiclePlatePredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehiclePlatePredictionStatus::Queued, $run->status);
        $this->assertSame('image/jpeg', $run->input_mime);
        $this->assertSame('jpg', $run->input_extension);
        $this->assertSame(VehiclePlateHybridContract::OPERATIONAL_EFFECT, $run->operational_effect);
        $this->assertSame(
            'intelligence/plate-hybrid/inputs/'
                .$run->tenant_id.'/'.$run->run_id.'.jpg',
            $run->input_stored_path,
        );
        Storage::disk('local')->assertExists($run->input_stored_path);
        Queue::assertPushed(
            RunVehiclePlatePrediction::class,
            fn (RunVehiclePlatePrediction $job): bool => $job->runId === $run->run_id
                && $job->tenantId === $run->tenant_id
                && $job->actorId === $fixture['user']->id
                && $job->queue === 'intelligence',
        );

        (new RunVehiclePlatePrediction($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteVehiclePlatePrediction::class));

        $completed = VehiclePlatePredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehiclePlatePredictionStatus::Succeeded, $completed->status);
        $this->assertSame('complete_primary_suggestion', $completed->suggestion_status);
        $this->assertSame('12345|أ|7', $completed->suggested_canonical);
        $this->assertSame('12345 | أ | 7', $completed->display_text);
        $this->assertSame('0.9600000', $completed->confidence);
        $this->assertFalse($completed->fallback_executed);
        $this->assertSame(
            $before,
            Vehicle::withoutGlobalScopes()->findOrFail($fixture['vehicle']->id)->registration_number,
        );

        $page = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-plates.index'))
            ->assertOk()
            ->assertSee('12345 | أ | 7')
            ->assertSee('Confiance non calibrée')
            ->assertDontSee($completed->input_stored_path)
            ->assertDontSee($completed->input_sha256);
        $this->assertStringNotContainsString('raw_text', $page->getContent());

        $input = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-plates.input', $completed))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
        $this->assertEqualsCanonicalizing(
            ['private', 'no-store', 'max-age=0'],
            array_map('trim', explode(',', (string) $input->headers->get('cache-control'))),
        );
        $this->assertSame($this->jpegBytes(), $input->streamedContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_plate.run_queued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_plate.run_succeeded']);
        $audits = AuditLog::withoutGlobalScopes()
            ->whereIn('action', [
                'prediction.vehicle_plate.run_queued',
                'prediction.vehicle_plate.run_succeeded',
            ])->get();
        $serialized = json_encode($audits->pluck('new_values')->all(), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('12345|أ|7', $serialized);
    }

    public function test_human_correction_is_append_only_training_feedback_without_operational_effect(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture);
        $before = $fixture['vehicle']->registration_number;

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.reviews.store', $run), [
                'decision' => VehiclePlateReviewDecision::Corrected->value,
                'verified_canonical' => '54321 | ب | 8',
                'note' => 'Correction visuelle du crop.',
            ])
            ->assertRedirect(route('intelligence.vehicle-plates.index'))
            ->assertSessionHas('status');

        $review = VehiclePlatePredictionReview::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehiclePlateReviewDecision::Corrected, $review->decision);
        $this->assertSame('54321|ب|8', $review->verified_canonical);
        $this->assertSame(VehiclePlateHybridContract::OPERATIONAL_EFFECT, $review->effect);
        $this->assertSame(
            $before,
            Vehicle::withoutGlobalScopes()->findOrFail($fixture['vehicle']->id)->registration_number,
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.vehicle_plate.human_correction_recorded',
        ]);
        $audit = AuditLog::withoutGlobalScopes()
            ->where('action', 'prediction.vehicle_plate.human_correction_recorded')
            ->firstOrFail();
        $serialized = json_encode($audit->new_values, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('54321|ب|8', $serialized);

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.reviews.store', $run), [
                'decision' => VehiclePlateReviewDecision::Confirmed->value,
                'verified_canonical' => '12345|أ|7',
            ])
            ->assertStatus(409);
        $this->assertSame(1, VehiclePlatePredictionReview::withoutGlobalScopes()->count());

        $this->assertPostgreSqlConstraint(fn () => DB::table('vehicle_plate_prediction_reviews')
            ->where('id', $review->id)
            ->update(['verified_canonical' => '999|د|9']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('vehicle_plate_prediction_runs')
            ->where('id', $run->id)
            ->update(['suggested_canonical' => '999|د|9']));
    }

    public function test_confirmation_must_equal_complete_suggestion_and_partial_result_requires_correction(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture);

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.reviews.store', $run), [
                'decision' => 'confirmed',
                'verified_canonical' => '999|د|9',
            ])
            ->assertSessionHasErrors('decision');
        $this->assertSame(0, VehiclePlatePredictionReview::withoutGlobalScopes()->count());

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.reviews.store', $run), [
                'decision' => 'confirmed',
                'verified_canonical' => '12345|أ|7',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('vehicle_plate_prediction_reviews', [
            'decision' => 'confirmed',
            'verified_canonical' => '12345|أ|7',
            'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
        ]);
    }

    public function test_invalid_worker_safeguard_fails_closed_and_keeps_registration(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime(automaticVehicleUpdate: true);
        Queue::fake();
        $before = $fixture['vehicle']->registration_number;
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->upload(),
            ])
            ->assertRedirect();
        $run = VehiclePlatePredictionRun::withoutGlobalScopes()->firstOrFail();
        $job = new RunVehiclePlatePrediction($run->run_id, $run->tenant_id, $run->requested_by);

        $failure = null;
        try {
            $job->handle(app(ExecuteVehiclePlatePrediction::class));
        } catch (VehiclePlateHybridExecutionException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(VehiclePlateHybridExecutionException::class, $failure);
        $this->assertSame('PLATE_OUTPUT_CONTRACT_INVALID', $failure->failureCode());
        $job->failed($failure);

        $this->assertDatabaseHas('vehicle_plate_prediction_runs', [
            'id' => $run->id,
            'status' => 'failed',
            'failure_code' => 'PLATE_OUTPUT_CONTRACT_INVALID',
        ]);
        $this->assertSame(
            $before,
            Vehicle::withoutGlobalScopes()->findOrFail($fixture['vehicle']->id)->registration_number,
        );
    }

    public function test_tenant_agency_permission_and_private_crop_boundaries_are_enforced(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture);
        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence OCR B']),
        );
        $otherManager = $this->user($fixture, 'fleet-manager', $otherAgency);
        $foreign = $this->fixture();

        $this->actingAs($viewer)
            ->get(route('intelligence.vehicle-plates.index'))
            ->assertOk();
        $this->actingAs($viewer)
            ->post(route('intelligence.vehicle-plates.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->upload(),
            ])
            ->assertForbidden();
        $this->actingAs($otherManager)
            ->get(route('intelligence.vehicle-plates.input', $run))
            ->assertForbidden();
        $this->actingAs($otherManager)
            ->post(route('intelligence.vehicle-plates.reviews.store', $run), [
                'decision' => 'ignored',
            ])
            ->assertForbidden();
        $this->actingAs($foreign['user'])
            ->get(route('intelligence.vehicle-plates.input', $run))
            ->assertNotFound();

        foreach (['tenant-owner', 'agency-manager', 'fleet-manager'] as $role) {
            $this->assertTrue($this->user(
                $fixture,
                $role,
                $role === 'tenant-owner' ? null : $fixture['agency'],
            )->hasPermission('prediction.plate.review'), $role);
        }
        foreach (['rental-agent', 'viewer-auditor'] as $role) {
            $this->assertFalse($this->user($fixture, $role, $fixture['agency'])
                ->hasPermission('prediction.plate.review'), $role);
        }
    }

    public function test_schema_routes_and_permission_contract_are_present(): void
    {
        foreach ([
            'intelligence.vehicle-plates.index',
            'intelligence.vehicle-plates.store',
            'intelligence.vehicle-plates.input',
            'intelligence.vehicle-plates.reviews.store',
        ] as $route) {
            $this->assertTrue(app('router')->has($route), $route);
        }
        foreach (['vehicle_plate_prediction_runs', 'vehicle_plate_prediction_reviews'] as $table) {
            $this->assertTrue(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists(), $table);
        }
        $this->assertDatabaseHas('permissions', [
            'slug' => 'prediction.plate.review',
            'group' => 'prediction',
        ]);
        $this->assertFalse((bool) config(
            'intelligence.vehicle_plate_hybrid_review.automatic_actions_allowed',
        ));
        $this->assertFalse((bool) config(
            'intelligence.vehicle_plate_hybrid_review.operational_table_writes_allowed',
        ));
        $this->assertSame(
            VehiclePlateHybridContract::OPERATIONAL_EFFECT,
            config('intelligence.vehicle_plate_hybrid_review.decision_effect'),
        );
    }

    private function completedRun(array $fixture): VehiclePlatePredictionRun
    {
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-plates.store'), [
                'vehicle_id' => $fixture['vehicle']->id,
                'image' => $this->upload(),
            ])
            ->assertRedirect();
        $run = VehiclePlatePredictionRun::withoutGlobalScopes()->firstOrFail();
        (new RunVehiclePlatePrediction($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteVehiclePlatePrediction::class));

        return VehiclePlatePredictionRun::withoutGlobalScopes()->findOrFail($run->id);
    }

    private function enableRuntime(bool $automaticVehicleUpdate = false): void
    {
        config(['intelligence.vehicle_plate_hybrid_review.enabled' => true]);
        $runtime = $this->mock(VehiclePlateHybridRuntime::class);
        $runtime->shouldReceive('configured')->andReturnTrue();
        $runtime->shouldReceive('execute')
            ->andReturnUsing(fn (VehiclePlatePredictionRun $run): string => $this->resultJson(
                $run->run_id,
                $automaticVehicleUpdate,
            ));
        $contents = $this->jpegBytes();
        $sanitizer = $this->mock(VehiclePlateImageSanitizer::class);
        $sanitizer->shouldReceive('sanitize')->andReturn(new SanitizedVehiclePlateImage(
            contents: $contents,
            mime: 'image/jpeg',
            extension: 'jpg',
            bytes: strlen($contents),
            sha256: hash('sha256', $contents),
            width: 4,
            height: 2,
        ));
    }

    private function fixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Entreprise test OCR plaque']);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence test OCR plaque']),
        );
        $user = $this->user(
            compact('tenant', 'agency'),
            $roleSlug,
            $roleSlug === 'tenant-owner' ? null : $agency,
        );
        $vehicle = app(TenantContext::class)->run($tenant, function () use ($agency): Vehicle {
            $category = VehicleCategory::create([
                'code' => 'PLATE',
                'name' => 'Catégorie OCR plaque',
                'is_active' => true,
            ]);

            return Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'RF-PLATE-001',
                'vin' => 'PLATEOCRVIN000001',
                'brand' => 'RentFleet',
                'model' => 'Plate Test',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'manual',
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

    private function upload(): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if (! is_string($bytes)) {
            throw new \LogicException('Fixture PNG invalide.');
        }

        return UploadedFile::fake()->createWithContent('plate.png', $bytes);
    }

    private function jpegBytes(): string
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAACAAQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD9U6KKKAP/2Q==',
            true,
        );
        if (! is_string($bytes)) {
            throw new \LogicException('Fixture JPEG invalide.');
        }

        return $bytes;
    }

    private function resultJson(string $cropId, bool $automaticVehicleUpdate): string
    {
        $component = static fn (string $role, string $value): array => [
            'role' => $role,
            'value' => $value,
            'confidence' => 0.96,
            'support' => 1,
            'evidence' => ['full:original'],
            'inferred_from_latin' => false,
        ];
        $payload = [
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
                'automatic_vehicle_update_allowed' => $automaticVehicleUpdate,
                'operational_effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                'second_ocr_model_used' => false,
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function assertPostgreSqlConstraint(callable $callback): void
    {
        try {
            $callback();
            $this->fail('PostgreSQL aurait dû refuser cette mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
        }
    }
}
