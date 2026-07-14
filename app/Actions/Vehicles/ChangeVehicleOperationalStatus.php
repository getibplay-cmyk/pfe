<?php

namespace App\Actions\Vehicles;

use App\Enums\VehicleOperationalStatus;
use App\Models\Vehicle;
use App\Models\VehicleStatusHistory;
use Illuminate\Support\Facades\DB;

class ChangeVehicleOperationalStatus
{
    public function handle(Vehicle $vehicle, VehicleOperationalStatus $status, ?string $reason, ?int $actorId): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $status, $reason, $actorId) {
            $locked = Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
            $from = $locked->operational_status;
            if ($from === $status) {
                return $locked;
            }
            $locked->forceFill(['operational_status' => $status])->save();
            VehicleStatusHistory::create(['vehicle_id' => $locked->id, 'from_status' => $from, 'to_status' => $status, 'reason' => $reason, 'changed_by' => $actorId]);

            return $locked->refresh();
        });
    }
}
