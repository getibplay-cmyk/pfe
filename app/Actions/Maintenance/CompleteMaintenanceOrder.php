<?php

namespace App\Actions\Maintenance;

use App\Actions\Finance\CreateExpense;
use App\Actions\Vehicles\ChangeVehicleOperationalStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteMaintenanceOrder
{
    public function __construct(private CreateExpense $createExpense, private ChangeVehicleOperationalStatus $changeVehicleStatus, private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, array $data, int $actorId): MaintenanceOrder
    {
        $cost = DecimalMoney::toMinorUnits($data['actual_cost'] ?? '0.00');

        return DB::transaction(function () use ($order, $data, $actorId, $cost) {
            $locked = MaintenanceOrder::with(['vehicle', 'vehicleBlock'])->whereKey($order)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'in_progress') {
                throw ValidationException::withMessages(['maintenance' => 'Seule une maintenance en cours peut être terminée.']);
            }
            $mileage = $data['mileage'] ?? $locked->vehicle->current_mileage;
            if ($mileage < $locked->vehicle->current_mileage) {
                throw ValidationException::withMessages(['mileage' => 'Le kilométrage ne peut pas diminuer.']);
            }
            $locked->forceFill([
                'status' => 'completed', 'actual_end_at' => now(), 'actual_cost' => DecimalMoney::fromMinorUnits($cost),
                'next_due_date' => $data['next_due_date'] ?? null, 'next_due_mileage' => $data['next_due_mileage'] ?? null, 'completed_by' => $actorId,
            ])->save();
            $locked->vehicle->forceFill(['current_mileage' => $mileage])->save();
            $locked->vehicleBlock?->forceFill(['status' => 'released', 'released_at' => now()])->save();
            if ($cost > 0) {
                $this->createExpense->handle(['agency_id' => $locked->agency_id, 'vehicle_id' => $locked->vehicle_id, 'maintenance_order_id' => $locked->id, 'category' => 'maintenance', 'description' => 'Maintenance '.$locked->maintenance_number, 'amount' => DecimalMoney::fromMinorUnits($cost), 'expense_date' => today(), 'supplier' => $locked->supplier], $actorId);
            }
            if (($data['return_to_active'] ?? false) === true) {
                $this->changeVehicleStatus->handle($locked->vehicle, VehicleOperationalStatus::Active, 'Retour actif confirmé après maintenance', $actorId);
            }
            MaintenanceStatusHistory::create(['maintenance_order_id' => $locked->id, 'from_status' => 'in_progress', 'to_status' => 'completed', 'reason' => $data['reason'] ?? null, 'changed_by' => $actorId]);
            $this->audit->record('maintenance.completed', $locked, ['status' => 'in_progress'], ['status' => 'completed', 'actual_cost' => $locked->actual_cost]);

            return $locked->refresh();
        });
    }
}
