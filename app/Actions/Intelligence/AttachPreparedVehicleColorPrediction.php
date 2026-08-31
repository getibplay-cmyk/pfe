<?php

namespace App\Actions\Intelligence;

use App\Enums\VehicleColorPredictionStatus;
use App\Models\Vehicle;
use App\Models\VehicleColorPredictionRun;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AttachPreparedVehicleColorPrediction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Vehicle $vehicle, string $runId, int $actorId): VehicleColorPredictionRun
    {
        $run = VehicleColorPredictionRun::query()
            ->where('run_id', $runId)
            ->lockForUpdate()
            ->first();

        if ($run === null
            || $run->vehicle_id !== null
            || $run->agency_id !== $vehicle->agency_id
            || $run->requested_by !== $actorId
            || $run->status !== VehicleColorPredictionStatus::Succeeded
            || ! $run->hasDisplayableCandidate()) {
            throw ValidationException::withMessages([
                'color_prediction_run' => 'Cette analyse couleur ne peut pas être associée à ce véhicule.',
            ]);
        }

        $run->forceFill(['vehicle_id' => $vehicle->id])->save();

        $submittedColor = Str::lower(trim((string) $vehicle->color));
        $suggestedValues = [
            Str::lower((string) $run->suggested_color),
            Str::lower(VehicleColorContract::label($run->suggested_color)),
        ];
        $this->audit->record('prediction.vehicle_color.preparation_linked', $run, [], [
            'run_id' => $run->run_id,
            'vehicle_id' => $vehicle->id,
            'suggestion_used' => in_array($submittedColor, $suggestedValues, true),
            'human_color_preserved' => true,
            'effect' => VehicleColorContract::OPERATIONAL_EFFECT,
        ]);

        return $run;
    }
}
