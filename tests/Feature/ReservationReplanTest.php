<?php

namespace Tests\Feature;

use App\Actions\Reservations\ReplanConfirmedReservation;
use App\Enums\ReservationStatus;
use App\Models\VehicleBlock;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsRentalScenario;
use Tests\TestCase;

class ReservationReplanTest extends TestCase
{
    use BuildsRentalScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00 UTC'));
        Storage::fake('local');
    }

    public function test_preview_does_not_move_and_confirm_preserves_original_price_with_idempotent_retry(): void
    {
        $f = $this->scenario();
        $original = $f['reservation']->pricing_snapshot;
        $this->actingAs($f['user'])->post(route('fleet.planning.preview', $f['reservation']), ['vehicle_id' => $f['vehicle']->id, 'shift_days' => 2])->assertOk()->assertSee('Confirmer');
        $this->within($f, function () use ($f, $original) {
            $action = app(ReplanConfirmedReservation::class);
            $preview = $action->preview($f['user'], $f['reservation'], ['vehicle_id' => $f['vehicle']->id, 'shift_days' => 2]);
            $this->assertSame(ReservationStatus::Confirmed, $f['reservation']->fresh()->status);
            $replacement = $action->confirm($f['user'], $f['reservation'], $preview['token'], 'Dates convenues avec le client.');
            $this->assertSame(ReservationStatus::Cancelled, $f['reservation']->fresh()->status);
            $this->assertSame($original, $f['reservation']->fresh()->pricing_snapshot);
            $this->assertSame(ReservationStatus::Confirmed, $replacement->status);
            $this->assertSame($f['reservation']->starts_at->addDays(2)->timestamp, $replacement->starts_at->timestamp);
            $this->assertSame($replacement->id, $action->confirm($f['user'], $f['reservation'], $preview['token'], 'Même demande.')->id);
            $this->assertSame(1, VehicleBlock::where('status', 'active')->count());
            $this->assertSame(1, DB::table('reservation_replans')->count());
        });
        $this->get(route('reservations.show', $f['reservation']))->assertOk()->assertSee('Consulter le dossier lié');
    }

    public function test_conflict_appearing_after_preview_rolls_back_the_entire_move(): void
    {
        $f = $this->scenario();
        $this->within($f, function () use ($f) {
            $action = app(ReplanConfirmedReservation::class);
            $preview = $action->preview($f['user'], $f['reservation'], ['vehicle_id' => $f['vehicle']->id, 'shift_days' => 2]);
            VehicleBlock::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'block_type' => 'manual', 'starts_at' => $preview['start'], 'ends_at' => $preview['end'], 'status' => 'active', 'reason' => 'Indisponibilité test', 'created_by' => $f['user']->id]);
            try {
                $action->confirm($f['user'], $f['reservation'], $preview['token'], 'Nouvelle période.');
                $this->fail('A conflicting move was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame(ReservationStatus::Confirmed, $f['reservation']->fresh()->status);
            $this->assertSame(0, DB::table('reservation_replans')->count());
        });
    }

    public function test_price_change_requires_another_preview_and_cross_tenant_is_hidden(): void
    {
        $f = $this->scenario();
        $token = $this->within($f, function () use ($f) {
            $preview = app(ReplanConfirmedReservation::class)->preview($f['user'], $f['reservation'], ['vehicle_id' => $f['vehicle']->id, 'shift_days' => 1]);
            $f['pricing']->update(['daily_rate' => '600.00']);

            return $preview['token'];
        });
        $this->actingAs($f['user'])->post(route('fleet.planning.confirm', $f['reservation']), ['proposal' => $token, 'reason' => 'Nouvelles dates.', 'confirmed' => true])->assertConflict();
        $foreign = $this->scenario();
        $this->actingAs($foreign['user'])->get(route('fleet.planning.edit', $f['reservation']))->assertNotFound();
    }

    public function test_contract_backed_reservations_cannot_be_moved(): void
    {
        $f = $this->scenario();
        $this->readyScenarioContract($f);
        $this->actingAs($f['user'])->get(route('fleet.planning.edit', $f['reservation']))->assertForbidden();
    }
}
