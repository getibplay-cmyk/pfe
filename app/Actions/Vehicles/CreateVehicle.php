<?php

namespace App\Actions\Vehicles;

use App\Actions\Intelligence\AttachPreparedVehicleColorPrediction;
use App\Enums\VehicleOperationalStatus;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleStatusHistory;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;

class CreateVehicle
{
    public function __construct(
        private readonly AgencyAccess $agencyAccess,
        private readonly AttachPreparedVehicleColorPrediction $attachColorPrediction,
    ) {}

    public function handle(
        array $data,
        ?int $actorId,
        ?string $colorPredictionRunId = null,
    ): Vehicle {
        $data['agency_id'] = $this->agencyAccess->required($data['agency_id'] ?? null);
        VehicleCategory::findOrFail($data['vehicle_category_id']);

        return DB::transaction(function () use ($data, $actorId, $colorPredictionRunId) {
            $vehicle = Vehicle::create($data);
            $vehicle->forceFill(['operational_status' => VehicleOperationalStatus::Active])->save();
            VehicleStatusHistory::create(['vehicle_id' => $vehicle->id, 'from_status' => null, 'to_status' => VehicleOperationalStatus::Active, 'changed_by' => $actorId]);
            if ($colorPredictionRunId !== null) {
                if ($actorId === null) {
                    throw new \LogicException('A vehicle color preparation requires an authenticated actor.');
                }
                $this->attachColorPrediction->handle($vehicle, $colorPredictionRunId, $actorId);
            }

            return $vehicle;
        });
    }
}
