<?php

namespace App\Actions\Vehicles;

use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;

class UpdateVehicle
{
    public function handle(Vehicle $vehicle, array $data): Vehicle
    {
        Agency::findOrFail($data['agency_id']);
        VehicleCategory::findOrFail($data['vehicle_category_id']);
        $vehicle->update($data);

        return $vehicle->refresh();
    }
}
