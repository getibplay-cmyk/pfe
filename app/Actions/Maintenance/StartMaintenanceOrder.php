<?php

namespace App\Actions\Maintenance;

use App\Actions\Vehicles\ChangeVehicleOperationalStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Maintenance\MaintenanceTransition;
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
            $blocks = VehicleBlock::query()->where('maintenance_order_id', $locked->id)->lockForUpdate()->get();
            $block = $blocks->first();
            if ($blocks->count() !== 1 || ! $block || ! $this->isCoherentBlock($block, $locked)) {
                throw ValidationException::withMessages(['maintenance' => 'Le bloc actif de cette maintenance est absent ou incohérent.']);
            }
            MaintenanceTransition::allow('approved', 'in_progress');
            $locked->forceFill(['status' => 'in_progress', 'actual_start_at' => now(), 'mileage_at_opening' => $locked->mileage_at_opening ?? $locked->vehicle->current_mileage])->save();
            $this->changeVehicleStatus->handle($locked->vehicle, VehicleOperationalStatus::Maintenance, 'Maintenance '.$locked->maintenance_number, $actorId);
            MaintenanceStatusHistory::create(['maintenance_order_id' => $locked->id, 'from_status' => 'approved', 'to_status' => 'in_progress', 'changed_by' => $actorId]);
            $this->audit->record('maintenance.started', $locked, ['status' => 'approved'], ['status' => 'in_progress']);

            return $locked->refresh();
        });
    }

    private function isCoherentBlock(VehicleBlock $block, MaintenanceOrder $order): bool
    {
        return $block->tenant_id === $order->tenant_id
            && $block->agency_id === $order->agency_id
            && $block->vehicle_id === $order->vehicle_id
            && $block->block_type->value === 'maintenance'
            && $block->status->value === 'active'
            && $block->starts_at->equalTo($order->scheduled_start_at)
            && $block->ends_at->equalTo($order->scheduled_end_at);
    }
}
