<?php

namespace App\Actions\Intelligence;

use App\Enums\VehiclePlatePredictionStatus;
use App\Models\Vehicle;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use Illuminate\Validation\ValidationException;

final class AttachPreparedVehiclePlatePrediction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Vehicle $vehicle, string $runId, int $actorId): VehiclePlatePredictionRun
    {
        $run = VehiclePlatePredictionRun::query()
            ->where('run_id', $runId)
            ->lockForUpdate()
            ->first();

        if ($run === null
            || $run->vehicle_id !== null
            || $run->agency_id !== $vehicle->agency_id
            || $run->requested_by !== $actorId
            || $run->status !== VehiclePlatePredictionStatus::Succeeded
            || ! $run->hasCompleteSuggestion()) {
            throw ValidationException::withMessages([
                'plate_prediction_run' => 'Cette lecture d’immatriculation ne peut pas être associée à ce véhicule.',
            ]);
        }

        $run->forceFill(['vehicle_id' => $vehicle->id])->save();

        $this->audit->record('prediction.vehicle_plate.preparation_linked', $run, [], [
            'run_id' => $run->run_id,
            'vehicle_id' => $vehicle->id,
            'suggestion_used' => hash_equals(
                (string) $run->suggested_canonical,
                (string) $vehicle->registration_number,
            ),
            'human_registration_preserved' => true,
            'effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
        ]);

        return $run;
    }
}
