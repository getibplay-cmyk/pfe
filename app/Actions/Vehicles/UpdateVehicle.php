<?php

namespace App\Actions\Vehicles;

use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Tenancy\AgencyAccess;

class UpdateVehicle
{
    public function __construct(private readonly AgencyAccess $agencyAccess) {}

    public function handle(Vehicle $vehicle, array $data): Vehicle
    {
        $data['agency_id'] = $this->agencyAccess->required($data['agency_id'] ?? null);
        VehicleCategory::findOrFail($data['vehicle_category_id']);
        $vehicle->update($data);

        return $vehicle->refresh();
    }
}
