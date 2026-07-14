<?php

namespace App\Actions\Vehicles;

use App\Enums\VehicleOperationalStatus;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleStatusHistory;
use Illuminate\Support\Facades\DB;

class CreateVehicle
{
    public function handle(array $data, ?int $actorId): Vehicle
    {
        Agency::findOrFail($data['agency_id']);
        VehicleCategory::findOrFail($data['vehicle_category_id']);

        return DB::transaction(function () use ($data, $actorId) {
            $vehicle = Vehicle::create($data);
            $vehicle->forceFill(['operational_status' => VehicleOperationalStatus::Active])->save();
            VehicleStatusHistory::create(['vehicle_id' => $vehicle->id, 'from_status' => null, 'to_status' => VehicleOperationalStatus::Active, 'changed_by' => $actorId]);

            return $vehicle;
        });
    }
}
