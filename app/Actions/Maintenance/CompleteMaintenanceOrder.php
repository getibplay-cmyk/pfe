<?php

namespace App\Actions\Maintenance;

use App\Actions\Finance\CreateExpense;
use App\Actions\Vehicles\ChangeVehicleOperationalStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Maintenance\MaintenanceTransition;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CompleteMaintenanceOrder
{
    public function __construct(private CreateExpense $createExpense, private ChangeVehicleOperationalStatus $changeVehicleStatus, private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, array $data, int $actorId): MaintenanceOrder
    {
        if (! array_key_exists('return_to_active', $data) || ! is_bool($data['return_to_active'])) {
            throw ValidationException::withMessages(['return_to_active' => 'La décision humaine sur le retour à l’état actif est obligatoire.']);
        }
        try {
            $cost = DecimalMoney::toMinorUnits($data['actual_cost'] ?? '0.00');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['actual_cost' => 'Le coût réel doit être un montant décimal positif ou nul.']);
        }

        return DB::transaction(function () use ($order, $data, $actorId, $cost) {
            $locked = MaintenanceOrder::whereKey($order)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'in_progress') {
                throw ValidationException::withMessages(['maintenance' => 'Seule une maintenance en cours peut être terminée.']);
            }
            $vehicle = Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();
            $blocks = VehicleBlock::query()->where('maintenance_order_id', $locked->id)->lockForUpdate()->get();
            $block = $blocks->first();
            if ($blocks->count() !== 1 || ! $block || ! $this->isCoherentBlock($block, $locked)) {
                throw ValidationException::withMessages(['maintenance' => 'Le bloc actif de cette maintenance est absent ou incohérent.']);
            }
            $mileage = $data['mileage'] ?? $vehicle->current_mileage;
            if ($mileage < $vehicle->current_mileage || ($locked->mileage_at_opening !== null && $mileage < $locked->mileage_at_opening)) {
                throw ValidationException::withMessages(['mileage' => 'Le kilométrage final doit être supérieur ou égal aux kilométrages d’ouverture et du véhicule.']);
            }
            if (isset($data['next_due_mileage']) && $data['next_due_mileage'] < $mileage) {
                throw ValidationException::withMessages(['next_due_mileage' => 'La prochaine échéance kilométrique ne peut pas être antérieure au kilométrage final.']);
            }
            MaintenanceTransition::allow('in_progress', 'completed');
            $locked->forceFill([
                'status' => 'completed', 'actual_end_at' => now(), 'actual_cost' => DecimalMoney::fromMinorUnits($cost),
                'next_due_date' => $data['next_due_date'] ?? null, 'next_due_mileage' => $data['next_due_mileage'] ?? null, 'completed_by' => $actorId,
            ])->save();
            $vehicle->forceFill(['current_mileage' => $mileage])->save();
            $block->forceFill(['status' => 'released', 'released_at' => now()])->save();
            if ($cost > 0) {
                if ($locked->expenses()->exists()) {
                    throw ValidationException::withMessages(['maintenance' => 'Une dépense est déjà rattachée à cette maintenance.']);
                }
                $this->createExpense->handle(['agency_id' => $locked->agency_id, 'vehicle_id' => $locked->vehicle_id, 'maintenance_order_id' => $locked->id, 'category' => 'maintenance', 'description' => 'Maintenance '.$locked->maintenance_number, 'amount' => DecimalMoney::fromMinorUnits($cost), 'expense_date' => today(), 'supplier' => $locked->supplier], $actorId);
            }
            if (($data['return_to_active'] ?? false) === true) {
                $this->changeVehicleStatus->handle($vehicle, VehicleOperationalStatus::Active, 'Retour actif confirmé après maintenance', $actorId);
            }
            MaintenanceStatusHistory::create(['maintenance_order_id' => $locked->id, 'from_status' => 'in_progress', 'to_status' => 'completed', 'reason' => $data['reason'] ?? null, 'changed_by' => $actorId]);
            $this->audit->record('maintenance.completed', $locked, ['status' => 'in_progress'], ['status' => 'completed', 'actual_cost' => $locked->actual_cost]);

            return $locked->refresh();
        });
    }

    private function isCoherentBlock(VehicleBlock $block, MaintenanceOrder $order): bool
    {
        return $block->tenant_id === $order->tenant_id
            && $block->agency_id === $order->agency_id
            && $block->vehicle_id === $order->vehicle_id
            && $block->block_type->value === 'maintenance'
            && $block->status->value === 'active';
    }
}
