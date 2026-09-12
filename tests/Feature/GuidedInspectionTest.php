<?php

namespace Tests\Feature;

use App\Actions\Rentals\ActivateRentalContract;
use App\Models\InspectionDraft;
use App\Models\InspectionDraftPhoto;
use App\Support\Rentals\GuidedInspection;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRentalScenario;
use Tests\TestCase;

class GuidedInspectionTest extends TestCase
{
    use BuildsRentalScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00 UTC'));
        Storage::fake('local');
    }

    public function test_draft_can_be_resumed_and_stale_requests_cannot_overwrite_it(): void
    {
        $f = $this->scenario();
        $contract = $this->acceptedScenarioContract($f);
        $url = route('inspections.guided.save', ['contract' => $contract, 'kind' => 'departure']);
        $this->actingAs($f['user'])->postJson($url, ['revision' => 0, ...$this->data()])->assertOk()->assertJsonPath('revision', 1);
        $this->postJson($url, ['revision' => 0, ...$this->data(), 'mileage' => 9999])->assertConflict();
        $this->get(route('inspections.guided.show', ['contract' => $contract, 'kind' => 'departure']))->assertOk()->assertSee('Brouillon repris');
        $this->within($f, function () use ($contract) {
            $this->assertSame(1010, InspectionDraft::sole()->data['mileage']);
            $this->assertSame(0, $contract->inspections()->count());
        });
        $foreign = $this->scenario();
        $this->actingAs($foreign['user'])->postJson($url, ['revision' => 1, ...$this->data()])->assertNotFound();
    }

    public function test_completion_requires_photos_or_an_explanation_and_remains_immutable(): void
    {
        $f = $this->scenario();
        $contract = $this->acceptedScenarioContract($f);
        $parameters = ['contract' => $contract, 'kind' => 'departure'];
        $this->actingAs($f['user'])->postJson(route('inspections.guided.complete', $parameters), ['revision' => 0, ...$this->data(), 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('photo_omission_reason');
        $this->postJson(route('inspections.guided.complete', $parameters), ['revision' => 0, ...$this->data(), 'photo_omission_reason' => 'Appareil photo indisponible lors de ce test.', 'confirmed' => true])->assertOk();
        $this->postJson(route('inspections.guided.save', $parameters), ['revision' => 1, ...$this->data(), 'mileage' => 2000])->assertConflict();
        $this->within($f, function () use ($contract) {
            $inspection = $contract->inspections()->sole();
            $this->assertSame(1010, $inspection->mileage);
            $this->assertSame('completed', $inspection->status->value);
            $this->assertCount(4, $inspection->items);
            $this->assertSame([], InspectionDraft::sole()->selected_photo_ids);
        });
    }

    public function test_photos_are_private_pinned_and_compared_by_angle_at_return(): void
    {
        $f = $this->scenario();
        $contract = $this->acceptedScenarioContract($f);
        $parameters = ['contract' => $contract, 'kind' => 'departure'];
        $this->actingAs($f['user'])->postJson(route('inspections.guided.photo', $parameters), ['revision' => 0, 'angle' => 'front', 'file' => UploadedFile::fake()->image('front.jpg', 100, 80)])->assertOk()->assertJsonPath('revision', 1);
        $photo = $this->within($f, fn () => InspectionDraftPhoto::sole());
        $this->get(route('inspections.guided.image', [...$parameters, 'photo' => $photo->id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson(route('inspections.guided.complete', $parameters), ['revision' => 1, ...$this->data(), 'photo_omission_reason' => 'Autres angles indisponibles pour ce test.', 'confirmed' => true])->assertOk();
        $this->within($f, fn () => app(ActivateRentalContract::class)->handle($contract->fresh(), $f['user']->id));
        $this->get(route('inspections.guided.show', ['contract' => $contract, 'kind' => 'return']))->assertOk()->assertSee('Photo de départ');
        $foreign = $this->scenario();
        $this->actingAs($foreign['user'])->get(route('inspections.guided.image', [...$parameters, 'photo' => $photo->id]))->assertNotFound();
    }

    public function test_non_images_and_unknown_angles_are_rejected_before_storage(): void
    {
        $f = $this->scenario();
        $contract = $this->acceptedScenarioContract($f);
        $url = route('inspections.guided.photo', ['contract' => $contract, 'kind' => 'departure']);
        $before = Storage::disk('local')->allFiles();
        $this->actingAs($f['user'])->postJson($url, ['revision' => 0, 'angle' => 'front', 'file' => UploadedFile::fake()->createWithContent('bad.jpg', 'not an image')])->assertUnprocessable();
        $this->postJson($url, ['revision' => 0, 'angle' => '../../private', 'file' => UploadedFile::fake()->image('photo.jpg', 100, 100)])->assertUnprocessable();
        $this->assertSame($before, Storage::disk('local')->allFiles());
    }

    private function data(): array
    {
        return ['mileage' => 1010, 'fuel_level' => '75.00', 'notes' => 'Observation de test', 'conditions' => array_fill_keys(array_keys(GuidedInspection::ITEMS), 'good')];
    }
}
