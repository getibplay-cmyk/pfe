<?php

namespace App\Actions\Intelligence;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\VehicleDamagePredictionStatus;
use App\Models\VehicleDamagePredictionRun;
use App\Models\VehicleInspection;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageInputArtifact;
use App\Support\Intelligence\VehicleDamage\VehicleDamageSuggestionPresenter;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AttachPreparedVehicleDamagePredictions
{
    public function __construct(
        private readonly VehicleDamageSuggestionPresenter $presenter,
        private readonly VehicleDamageInputArtifact $inputArtifact,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param list<string> $runIds */
    public function handle(
        VehicleInspection $inspection,
        array $runIds,
        int $actorId,
    ): void {
        $runIds = array_values(array_unique($runIds));
        if ($runIds === []) {
            return;
        }
        if ($inspection->inspection_type !== InspectionType::Return
            || $inspection->status !== InspectionStatus::Completed) {
            $this->invalid();
        }

        $runs = VehicleDamagePredictionRun::query()
            ->whereIn('run_id', $runIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('run_id');
        if ($runs->count() !== count($runIds)) {
            $this->invalid();
        }

        foreach ($runIds as $runId) {
            $run = $runs->get($runId);
            if (! $run instanceof VehicleDamagePredictionRun
                || $run->agency_id !== $inspection->agency_id
                || $run->rental_contract_id !== $inspection->rental_contract_id
                || $run->vehicle_id !== $inspection->vehicle_id
                || $run->vehicle_inspection_id !== null
                || $run->requested_by !== $actorId
                || $run->status !== VehicleDamagePredictionStatus::Succeeded
                || $run->quality_status !== 'usable'
                || $run->suggested_damage !== true
                || ! $this->inputArtifact->valid($run)) {
                $this->invalid();
            }

            try {
                if ($this->presenter->detections($run) === []) {
                    $this->invalid();
                }
            } catch (UnexpectedValueException) {
                $this->invalid();
            }

            $run->forceFill(['vehicle_inspection_id' => $inspection->id])->save();
            $this->audit->record(
                'prediction.vehicle_damage.preparation_linked',
                $run,
                [],
                [
                    'run_id' => $run->run_id,
                    'rental_contract_id' => $inspection->rental_contract_id,
                    'vehicle_inspection_id' => $inspection->id,
                    'vehicle_id' => $inspection->vehicle_id,
                    'human_observation_preserved' => true,
                    'effect' => VehicleDamageContract::OPERATIONAL_EFFECT,
                ],
            );
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'damage_prediction_runs' => 'Une suggestion de dommage ne peut pas être associée à cette inspection.',
        ]);
    }
}
