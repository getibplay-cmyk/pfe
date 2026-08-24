<?php

namespace Tests\Feature;

use App\Actions\Intelligence\ExecuteVehicleDamagePrediction;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\VehicleDamagePredictionStatus;
use App\Enums\VehicleDamageReviewDecision;
use App\Exceptions\VehicleDamageExecutionException;
use App\Jobs\RunVehicleDamagePrediction;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleDamagePredictionReview;
use App\Models\VehicleDamagePredictionRun;
use App\Models\VehicleInspection;
use App\Support\Intelligence\VehicleDamage\SanitizedVehicleDamageImage;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageImageSanitizer;
use App\Support\Intelligence\VehicleDamage\VehicleDamageInputArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleDamagePredictionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesPermissionsSeeder::class);
        config([
            'intelligence.vehicle_damage_v1.enabled' => false,
            'intelligence.vehicle_damage_v1.disk' => 'local',
            'intelligence.vehicle_damage_v1.python_binary' => 'python',
            'intelligence.vehicle_damage_v1.execution_provider' => 'CPUExecutionProvider',
            'intelligence.vehicle_damage_v1.runtime_script' => base_path(
                'scripts/intelligence/vehicle_damage/run_vehicle_damage_onnx.py',
            ),
            'intelligence.vehicle_damage_v1.image_sanitizer_script' => base_path(
                'scripts/intelligence/vehicle_damage/sanitize_return_image.py',
            ),
            'intelligence.vehicle_damage_v1.model_path' => '/private/models/damage/model.onnx',
            'intelligence.vehicle_damage_v1.model_card_path' => '/private/models/damage/model_card.json',
            'intelligence.vehicle_damage_v1.model_sha256' => str_repeat('a', 64),
            'intelligence.vehicle_damage_v1.model_card_sha256' => str_repeat('b', 64),
        ]);
    }

    public function test_feature_is_disabled_by_default_and_cannot_create_a_run(): void
    {
        $fixture = $this->fixture();
        Queue::fake();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.store'), [
                'vehicle_inspection_id' => $fixture['inspection']->id,
                'image' => $this->image(),
            ])
            ->assertForbidden();

        $this->assertSame(0, VehicleDamagePredictionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-damages.index'))
            ->assertOk()
            ->assertSee('Désactivé par défaut')
            ->assertSee('Aucune action automatique');
    }

    public function test_qualified_model_is_queued_executed_and_never_creates_business_damage(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $inspectionBefore = $fixture['inspection']->getAttributes();

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.store'), [
                'vehicle_inspection_id' => $fixture['inspection']->id,
                'image' => $this->image(),
            ])
            ->assertRedirect(route('intelligence.vehicle-damages.index'))
            ->assertSessionHas('status');

        $run = VehicleDamagePredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehicleDamagePredictionStatus::Queued, $run->status);
        $this->assertSame($fixture['inspection']->id, $run->vehicle_inspection_id);
        $this->assertSame(str_repeat('a', 64), $run->model_artifact_sha256);
        $this->assertSame(str_repeat('b', 64), $run->model_card_sha256);
        $this->assertSame(VehicleDamageContract::OPERATIONAL_EFFECT, $run->operational_effect);
        $this->assertSame(
            'intelligence/vehicle-damage/inputs/'.$run->tenant_id.'/'.$run->run_id.'.jpg',
            $run->input_stored_path,
        );
        Storage::disk('local')->assertExists($run->input_stored_path);
        Queue::assertPushed(
            RunVehicleDamagePrediction::class,
            fn (RunVehicleDamagePrediction $job): bool => $job->runId === $run->run_id
                && $job->tenantId === $fixture['tenant']->id
                && $job->actorId === $fixture['user']->id
                && $job->queue === 'intelligence',
        );

        Process::fake(['*' => Process::result(output: $this->resultJson($run))]);
        (new RunVehicleDamagePrediction($run->run_id, $run->tenant_id, $run->requested_by))
            ->handle(app(ExecuteVehicleDamagePrediction::class));

        $completed = VehicleDamagePredictionRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehicleDamagePredictionStatus::Succeeded, $completed->status);
        $this->assertSame('usable', $completed->quality_status);
        $this->assertTrue($completed->suggested_damage);
        $this->assertSame('0.9100000', $completed->max_probability_damage);
        $this->assertCount(2, $completed->candidate_regions);
        $this->assertSame(0, DamageReport::withoutGlobalScopes()->count());
        $this->assertSame(
            $inspectionBefore,
            VehicleInspection::withoutGlobalScopes()->findOrFail($fixture['inspection']->id)->getAttributes(),
        );
        Process::assertRan(fn ($process): bool => is_array($process->command)
            && $process->command[0] === 'python'
            && $process->command[1] === config('intelligence.vehicle_damage_v1.runtime_script')
            && in_array('--model-card', $process->command, true)
            && in_array('--max-patches', $process->command, true)
            && in_array('CPUExecutionProvider', $process->command, true)
            && $process->timeout === 120);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_damage.run_queued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_damage.run_succeeded']);

        $page = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-damages.index'))
            ->assertOk()
            ->assertSee('Zone candidate à vérifier')
            ->assertSee('91,00 %')
            ->assertSee('Les cadres rouges indiquent des patches candidats')
            ->assertDontSee($completed->input_stored_path)
            ->assertDontSee($completed->input_sha256)
            ->assertDontSee($completed->model_artifact_sha256);
        $this->assertStringNotContainsString('/private/models/', $page->getContent());

        $input = $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-damages.input', $completed))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
        $this->assertSame($this->sanitizedBytes(), $input->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.vehicle_damage.input_viewed']);
    }

    public function test_quality_abstention_cannot_be_confirmed_and_can_request_a_new_photo(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture, qualityAbstention: true);

        $this->actingAs($fixture['user'])
            ->get(route('intelligence.vehicle-damages.index'))
            ->assertOk()
            ->assertSee('Abstention qualité')
            ->assertSee('Contraste insuffisant')
            ->assertDontSee('Confirmer une zone visible');

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.reviews.store', $run), [
                'decision' => VehicleDamageReviewDecision::Confirmed->value,
            ])
            ->assertSessionHasErrors('decision');
        $this->assertSame(0, VehicleDamagePredictionReview::withoutGlobalScopes()->count());

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.reviews.store', $run), [
                'decision' => VehicleDamageReviewDecision::NewPhotoRequired->value,
                'note' => 'Reprendre la photo avec davantage de lumière.',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('vehicle_damage_prediction_reviews', [
            'decision' => VehicleDamageReviewDecision::NewPhotoRequired->value,
            'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
        ]);
        $this->assertSame(0, DamageReport::withoutGlobalScopes()->count());
    }

    public function test_human_confirmation_is_append_only_and_has_no_operational_effect(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture);

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.reviews.store', $run), [
                'decision' => VehicleDamageReviewDecision::Confirmed->value,
                'note' => 'Zone visible à contrôler dans le flux métier séparé.',
            ])
            ->assertRedirect(route('intelligence.vehicle-damages.index'))
            ->assertSessionHas('status');

        $review = VehicleDamagePredictionReview::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(VehicleDamageReviewDecision::Confirmed, $review->decision);
        $this->assertSame(VehicleDamageContract::OPERATIONAL_EFFECT, $review->effect);
        $this->assertSame(0, DamageReport::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'prediction.vehicle_damage.human_decision_recorded',
        ]);

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.reviews.store', $run), [
                'decision' => VehicleDamageReviewDecision::Rejected->value,
            ])
            ->assertStatus(409);
        $this->assertSame(1, VehicleDamagePredictionReview::withoutGlobalScopes()->count());
        $this->assertPostgreSqlConstraint(fn () => DB::table('vehicle_damage_prediction_reviews')
            ->where('id', $review->id)
            ->update(['decision' => 'rejected']));
        $this->assertPostgreSqlConstraint(fn () => DB::table('vehicle_damage_prediction_runs')
            ->where('id', $run->id)
            ->update(['max_probability_damage' => '0.9900000']));
    }

    public function test_only_completed_return_inspections_are_eligible(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $departure = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => VehicleInspection::create([
                'agency_id' => $fixture['agency']->id,
                'rental_contract_id' => $fixture['contract']->id,
                'vehicle_id' => $fixture['vehicle']->id,
                'inspection_type' => InspectionType::Departure,
                'status' => InspectionStatus::Draft,
                'inspected_at' => now(),
                'mileage' => 1000,
                'fuel_level' => '75.00',
                'created_by' => $fixture['user']->id,
            ]),
            $fixture['agency']->id,
        );

        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.store'), [
                'vehicle_inspection_id' => $departure->id,
                'image' => $this->image(),
            ])
            ->assertNotFound();
        $this->assertSame(0, VehicleDamagePredictionRun::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_tenant_agency_permission_and_private_image_boundaries_are_enforced(): void
    {
        $fixture = $this->fixture();
        $run = $this->completedRun($fixture);
        $viewer = $this->user($fixture, 'viewer-auditor', $fixture['agency']);
        $otherAgency = app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => Agency::factory()->create(['name' => 'Agence dommages B']),
        );
        $otherManager = $this->user($fixture, 'fleet-manager', $otherAgency);
        $foreign = $this->fixture();

        $this->actingAs($viewer)
            ->get(route('intelligence.vehicle-damages.index'))
            ->assertOk();
        $this->actingAs($viewer)
            ->post(route('intelligence.vehicle-damages.store'), [
                'vehicle_inspection_id' => $fixture['inspection']->id,
                'image' => $this->image(),
            ])
            ->assertForbidden();
        $this->actingAs($otherManager)
            ->get(route('intelligence.vehicle-damages.input', $run))
            ->assertForbidden();
        $this->actingAs($foreign['user'])
            ->get(route('intelligence.vehicle-damages.input', $run))
            ->assertNotFound();
        $this->actingAs($foreign['user'])
            ->post(route('intelligence.vehicle-damages.reviews.store', $run), [
                'decision' => 'rejected',
            ])
            ->assertNotFound();

        foreach (['tenant-owner', 'agency-manager', 'fleet-manager'] as $role) {
            $this->assertTrue($this->user(
                $fixture,
                $role,
                $role === 'tenant-owner' ? null : $fixture['agency'],
            )->hasPermission('prediction.damage.review'), $role);
        }
        foreach (['rental-agent', 'viewer-auditor'] as $role) {
            $this->assertFalse($this->user($fixture, $role, $fixture['agency'])
                ->hasPermission('prediction.damage.review'), $role);
        }
    }

    public function test_forged_closed_output_fails_without_leaking_private_values(): void
    {
        $fixture = $this->fixture();
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.store'), [
                'vehicle_inspection_id' => $fixture['inspection']->id,
                'image' => $this->image(),
            ])
            ->assertRedirect();
        $run = VehicleDamagePredictionRun::withoutGlobalScopes()->firstOrFail();
        $payload = json_decode($this->resultJson($run), true, flags: JSON_THROW_ON_ERROR);
        $payload['secret_path'] = '/private/models/forbidden.onnx';
        Process::fake(['*' => Process::result(output: json_encode($payload, JSON_THROW_ON_ERROR))]);
        $job = new RunVehicleDamagePrediction($run->run_id, $run->tenant_id, $run->requested_by);

        $failure = null;
        try {
            $job->handle(app(ExecuteVehicleDamagePrediction::class));
        } catch (VehicleDamageExecutionException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(VehicleDamageExecutionException::class, $failure);
        $job->failed($failure);

        $this->assertDatabaseHas('vehicle_damage_prediction_runs', [
            'id' => $run->id,
            'status' => VehicleDamagePredictionStatus::Failed->value,
            'failure_code' => 'DAMAGE_OUTPUT_CONTRACT_INVALID',
        ]);
        $this->assertFalse(AuditLog::withoutGlobalScopes()->get()->contains(
            static fn (AuditLog $audit): bool => str_contains(
                json_encode($audit->new_values, JSON_THROW_ON_ERROR),
                '/private/models/forbidden.onnx',
            ),
        ));
    }

    public function test_only_one_active_run_exists_per_inspection_and_stale_run_is_closed(): void
    {
        CarbonImmutable::setTestNow('2026-08-24 10:00:00+01:00');
        try {
            $fixture = $this->fixture();
            $this->enableRuntime();
            Queue::fake();
            $request = fn () => $this->actingAs($fixture['user'])
                ->post(route('intelligence.vehicle-damages.store'), [
                    'vehicle_inspection_id' => $fixture['inspection']->id,
                    'image' => $this->image(),
                ]);

            $request()->assertRedirect();
            $request()->assertStatus(409);
            $this->assertSame(1, VehicleDamagePredictionRun::withoutGlobalScopes()->count());

            CarbonImmutable::setTestNow('2026-08-24 10:16:00+01:00');
            $request()->assertRedirect();
            $runs = VehicleDamagePredictionRun::withoutGlobalScopes()->orderBy('id')->get();
            $this->assertCount(2, $runs);
            $this->assertSame(VehicleDamagePredictionStatus::Failed, $runs[0]->status);
            $this->assertSame('RUN_STALE_RECOVERED', $runs[0]->failure_code);
            $this->assertSame(VehicleDamagePredictionStatus::Queued, $runs[1]->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_routes_schema_permission_and_installer_contract_are_present(): void
    {
        foreach ([
            'intelligence.vehicle-damages.index',
            'intelligence.vehicle-damages.store',
            'intelligence.vehicle-damages.input',
            'intelligence.vehicle-damages.reviews.store',
        ] as $route) {
            $this->assertTrue(app('router')->has($route), $route);
        }
        foreach (['vehicle_damage_prediction_runs', 'vehicle_damage_prediction_reviews'] as $table) {
            $this->assertTrue(DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists(), $table);
        }
        $this->assertDatabaseHas('permissions', [
            'slug' => 'prediction.damage.review',
            'group' => 'prediction',
        ]);

        $invalidDirectory = Storage::disk('local')->path('invalid-damage-run');
        mkdir($invalidDirectory, 0700, true);
        $this->artisan('rentfleet:damage-v1:install', ['source' => $invalidDirectory])
            ->assertFailed();
        $this->assertFileDoesNotExist(config('intelligence.vehicle_damage_v1.model_path'));
    }

    private function completedRun(
        array $fixture,
        bool $qualityAbstention = false,
    ): VehicleDamagePredictionRun {
        $this->enableRuntime();
        Queue::fake();
        $this->actingAs($fixture['user'])
            ->post(route('intelligence.vehicle-damages.store'), [
                'vehicle_inspection_id' => $fixture['inspection']->id,
                'image' => $this->image(),
            ])
            ->assertRedirect();
        $run = VehicleDamagePredictionRun::withoutGlobalScopes()->latest('id')->firstOrFail();
        Process::fake(['*' => Process::result(output: $this->resultJson($run, $qualityAbstention))]);
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
            $contents = $this->sanitizedBytes();

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

    private function fixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create(['name' => 'Entreprise test dommages']);
        $agency = app(TenantContext::class)->run(
            $tenant,
            fn () => Agency::factory()->create(['name' => 'Agence test dommages']),
        );
        $user = $this->user(
            compact('tenant', 'agency'),
            $roleSlug,
            $roleSlug === 'tenant-owner' ? null : $agency,
        );

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $agency, $user): array {
            $category = VehicleCategory::create([
                'code' => 'DAMAGE-'.uniqid(),
                'name' => 'Catégorie dommages',
                'is_active' => true,
            ]);
            $vehicle = Vehicle::create([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'RF-DAMAGE-'.uniqid(),
                'brand' => 'RentFleet',
                'model' => 'Damage Test',
                'production_year' => 2026,
                'fuel_type' => 'petrol',
                'transmission' => 'automatic',
                'current_mileage' => 1000,
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
                'reservation_number' => 'RES-DAMAGE-'.uniqid(),
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
                'contract_number' => 'CTR-DAMAGE-'.uniqid(),
                'status' => 'return_pending',
                'expected_start_at' => now()->subDays(2),
                'expected_return_at' => now()->subDay(),
                'rental_subtotal' => '500.00',
                'additional_charges_total' => '0.00',
                'total_amount' => '500.00',
                'deposit_required' => '0.00',
                'currency' => 'MAD',
                'created_by' => $user->id,
            ]);
            $inspection = VehicleInspection::create([
                'agency_id' => $agency->id,
                'rental_contract_id' => $contract->id,
                'vehicle_id' => $vehicle->id,
                'inspection_type' => InspectionType::Return,
                'status' => InspectionStatus::Completed,
                'inspected_at' => now(),
                'mileage' => 1250,
                'fuel_level' => '65.00',
                'completed_by' => $user->id,
                'completed_at' => now(),
                'created_by' => $user->id,
            ]);

            return compact(
                'tenant',
                'agency',
                'user',
                'category',
                'vehicle',
                'customer',
                'reservation',
                'contract',
                'inspection',
            );
        }, $agency->id);
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

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('return.png', $this->pngBytes());
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if (! is_string($bytes)) {
            throw new \LogicException('La fixture PNG est invalide.');
        }

        return $bytes;
    }

    private function sanitizedBytes(): string
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==',
            true,
        );
        if (! is_string($bytes)) {
            throw new \LogicException('La fixture JPEG est invalide.');
        }

        return $bytes;
    }

    private function resultJson(
        VehicleDamagePredictionRun $run,
        bool $qualityAbstention = false,
    ): string {
        return json_encode([
            'schema_version' => VehicleDamageContract::RESULT_SCHEMA_VERSION,
            'model' => [
                'name' => VehicleDamageContract::MODEL_NAME,
                'version' => VehicleDamageContract::MODEL_VERSION,
                'artifact_sha256' => $run->model_artifact_sha256,
                'model_card_sha256' => $run->model_card_sha256,
                'decision_threshold' => VehicleDamageContract::DECISION_THRESHOLD,
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
                'status' => $qualityAbstention ? 'abstained' : 'usable',
                'reasons' => $qualityAbstention ? ['LOW_CONTRAST', 'POSSIBLY_BLURRED'] : [],
                'brightness' => 0.50,
                'contrast' => $qualityAbstention ? 0.01 : 0.20,
                'sharpness' => $qualityAbstention ? 0.01 : 0.30,
            ],
            'scan' => [
                'mode' => 'coarse_overlapping_patches',
                'evaluated_patches' => $qualityAbstention ? 0 : 12,
                'overlap_ratio' => VehicleDamageContract::OVERLAP_RATIO,
                'candidate_limit' => VehicleDamageContract::MAX_CANDIDATES,
            ],
            'result' => [
                'suggested_damage' => $qualityAbstention ? null : true,
                'max_probability_damage' => $qualityAbstention ? null : 0.91,
                'candidate_count' => $qualityAbstention ? 0 : 2,
                'candidate_regions' => $qualityAbstention ? [] : [
                    ['x' => 0, 'y' => 0, 'width' => 384, 'height' => 384, 'probability' => 0.91],
                    ['x' => 384, 'y' => 0, 'width' => 384, 'height' => 384, 'probability' => 0.78],
                ],
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
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function assertPostgreSqlConstraint(callable $operation): void
    {
        try {
            $operation();
            $this->fail('PostgreSQL devait refuser cette opération.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }
}
