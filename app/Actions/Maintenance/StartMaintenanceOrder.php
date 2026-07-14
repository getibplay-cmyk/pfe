<?php

namespace App\Actions\Maintenance;

use App\Actions\Vehicles\ChangeVehicleOperationalStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartMaintenanceOrder
{
    public function __construct(private ChangeVehicleOperationalStatus $changeVehicleStatus, private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, int $actorId): MaintenanceOrder
    {
        return DB::transaction(function () use ($order, $actorId) {
            $locked = MaintenanceOrder::with('vehicle')->whereKey($order)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['maintenance' => 'Seule une maintenance approuvée peut démarrer.']);
            }
            $locked->forceFill(['status' => 'in_progress', 'actual_start_at' => now(), 'mileage_at_opening' => $locked->mileage_at_opening ?? $locked->vehicle->current_mileage])->save();
            $this->changeVehicleStatus->handle($locked->vehicle, VehicleOperationalStatus::Maintenance, 'Maintenance '.$locked->maintenance_number, $actorId);
            MaintenanceStatusHistory::create(['maintenance_order_id' => $locked->id, 'from_status' => 'approved', 'to_status' => 'in_progress', 'changed_by' => $actorId]);
            $this->audit->record('maintenance.started', $locked, ['status' => 'approved'], ['status' => 'in_progress']);

            return $locked->refresh();
        });
    }
}
