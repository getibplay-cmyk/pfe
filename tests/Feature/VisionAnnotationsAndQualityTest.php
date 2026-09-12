<?php

namespace Tests\Feature;

use App\Models\ModelTrainingDataset;
use App\Models\VehicleColorPredictionRun;
use App\Models\VisionAnnotation;
use App\Support\Intelligence\IntelligencePrivateStorage;
use App\Support\Intelligence\Training\ModelQualityReport;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRentalScenario;
use Tests\TestCase;
use ZipArchive;

class VisionAnnotationsAndQualityTest extends TestCase
{
    use BuildsRentalScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00 UTC'));
        Storage::fake('local');
        Storage::fake(IntelligencePrivateStorage::DISK);
        config(['intelligence.export_hmac_key' => str_repeat('annotation-test-only-', 4)]);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    public function test_correction_is_validated_versioned_and_cannot_overwrite_another_revision(): void
    {
        $f = $this->scenario();
        $run = $this->color($f);
        $url = route('annotations.store', ['family' => 'color', 'run' => $run->run_id]);
        $this->actingAs($f['user'])->postJson($url, ['previous_revision' => 0, 'label' => 'white'])->assertUnprocessable();
        $this->postJson($url, ['previous_revision' => 0, 'label' => 'white', 'validated' => true])->assertRedirect();
        $this->postJson($url, ['previous_revision' => 0, 'label' => 'blue', 'validated' => true])->assertConflict();
        $this->postJson($url, ['previous_revision' => 1, 'label' => 'blue', 'validated' => true])->assertRedirect();
        $this->within($f, function () use ($f, $run) {
            $this->assertSame(['white', 'blue'], VisionAnnotation::orderBy('revision')->get()->map(fn ($a) => $a->truth['label'])->all());
            $this->assertSame('black', $run->fresh()->suggested_color);
            $this->assertNull($f['vehicle']->fresh()->color);
            $this->assertStringNotContainsString('white', DB::table('vision_annotations')->first()->truth);
        });
    }

    public function test_another_tenant_cannot_read_or_annotate_a_run_and_tampering_fails_closed(): void
    {
        $f = $this->scenario();
        $foreign = $this->scenario();
        $run = $this->color($f);
        $parameters = ['family' => 'color', 'run' => $run->run_id];
        $this->actingAs($foreign['user'])->get(route('annotations.edit', $parameters))->assertNotFound();
        $this->post(route('annotations.store', $parameters), ['previous_revision' => 0, 'label' => 'white', 'validated' => true])->assertNotFound();
        Storage::disk(IntelligencePrivateStorage::DISK)->put($run->input_stored_path, 'tampered');
        $this->actingAs($f['user'])->postJson(route('annotations.store', $parameters), ['previous_revision' => 0, 'label' => 'white', 'validated' => true])->assertConflict();
        $this->assertDatabaseCount('vision_annotations', 0);
    }

    public function test_export_pins_images_and_annotations_and_superseding_a_label_revokes_it(): void
    {
        $f = $this->scenario();
        $run = $this->color($f);
        $parameters = ['family' => 'color', 'run' => $run->run_id];
        $this->actingAs($f['user'])->post(route('annotations.store', $parameters), ['previous_revision' => 0, 'label' => 'white', 'validated' => true])->assertRedirect();
        $annotation = $this->within($f, fn () => VisionAnnotation::sole());
        $data = ['family' => 'color', 'name' => 'Images corrigées', 'annotations' => [$annotation->id], 'rights_confirmed' => true, 'labels_confirmed' => true];
        $this->post(route('annotations.export'), [...$data, 'rights_confirmed' => false])->assertSessionHasErrors('rights_confirmed');
        $this->post(route('annotations.export'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $dataset = $this->within($f, fn () => ModelTrainingDataset::sole());
        $this->assertFalse($dataset->shared);
        $response = $this->get(route('annotations.download', $dataset))->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $manifest = json_decode($zip->getFromName('dataset.json'), true, 16, JSON_THROW_ON_ERROR);
        $row = $manifest['rows'][0];
        $this->assertSame('white', $row['label']);
        $this->assertSame($row['image_sha256'], hash('sha256', $zip->getFromName('images/'.$row['image_sha256'].'.jpg')));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['group']);
        $zip->close();
        $this->post(route('annotations.store', $parameters), ['previous_revision' => 1, 'label' => 'blue', 'validated' => true])->assertRedirect();
        $this->get(route('annotations.download', $dataset))->assertGone();
        $this->within($f, fn () => $this->assertNotNull($dataset->fresh()->revoked_at));
    }

    public function test_quality_denominators_exclude_unreviewed_runs_and_other_tenants(): void
    {
        $f = $this->scenario();
        $a = $this->color($f);
        $b = $this->color($f);
        $this->color($f, false);
        $other = $this->scenario();
        $this->color($other);
        $this->actingAs($f['user'])->post(route('annotations.store', ['family' => 'color', 'run' => $a->run_id]), ['previous_revision' => 0, 'label' => 'white', 'validated' => true])->assertRedirect();
        $this->post(route('annotations.store', ['family' => 'color', 'run' => $b->run_id]), ['previous_revision' => 0, 'label' => 'black', 'validated' => true])->assertRedirect();
        $report = $this->within($f, fn () => app(ModelQualityReport::class)->build($f['user'], 30, null));
        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame(3, $row['completed']);
        $this->assertSame(2, $row['reviewed']);
        $this->assertSame(2, $row['comparable']);
        $this->assertSame(1, $row['corrected']);
        $this->assertSame(.5, $row['correction_rate']);
        $this->assertSame(1, $row['abstained']);
        $this->assertNull($row['change']);
        $this->get(route('model-quality.index'))->assertOk()->assertSee('Échantillon insuffisant');
    }

    private function color(array $f, bool $accepted = true): VehicleColorPredictionRun
    {
        return $this->within($f, function () use ($f, $accepted) {
            $key = (string) str()->uuid();
            $bytes = UploadedFile::fake()->image('vehicle.jpg', 100, 80)->getContent();
            $path = 'intelligence/color-v8/inputs/'.$f['tenant']->id.'/'.$key.'.jpg';
            Storage::disk(IntelligencePrivateStorage::DISK)->put($path, $bytes);

            return VehicleColorPredictionRun::create([
                'agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'run_id' => $key, 'requested_by' => $f['user']->id, 'status' => 'succeeded',
                'input_mime' => 'image/jpeg', 'input_extension' => 'jpg', 'input_bytes' => strlen($bytes), 'input_sha256' => hash('sha256', $bytes), 'input_stored_path' => $path,
                'suggested_color' => 'black', 'confidence' => $accepted ? '.98' : '.8', 'model_accepted' => $accepted, 'probabilities' => [...array_fill_keys(VehicleColorContract::CLASSES, $accepted ? .0025 : .025), 'black' => $accepted ? .98 : .8],
                'model_name' => VehicleColorContract::MODEL_NAME, 'model_version' => VehicleColorContract::MODEL_VERSION, 'model_artifact_sha256' => VehicleColorContract::MODEL_ARTIFACT_SHA256,
                'metadata_sha256' => VehicleColorContract::METADATA_SHA256, 'accepted_threshold' => VehicleColorContract::ACCEPTED_THRESHOLD, 'operational_effect' => VehicleColorContract::OPERATIONAL_EFFECT,
                'requested_at' => now()->subHour(), 'started_at' => now()->subHour(), 'finished_at' => now()->subMinutes(59),
            ]);
        });
    }
}
