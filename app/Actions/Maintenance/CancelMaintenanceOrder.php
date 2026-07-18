<?php

namespace App\Actions\Maintenance;

use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Maintenance\MaintenanceTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelMaintenanceOrder
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, string $reason, int $actorId): MaintenanceOrder
    {
        return DB::transaction(function () use ($order, $reason, $actorId) {
            $locked = MaintenanceOrder::whereKey($order)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['planned', 'approved'], true)) {
                throw ValidationException::withMessages(['maintenance' => 'Cette maintenance ne peut plus être annulée.']);
            }
            $from = $locked->status;
            $blocks = VehicleBlock::query()->where('maintenance_order_id', $locked->id)->lockForUpdate()->get();
            if ($from === 'planned' && $blocks->isNotEmpty()) {
                throw ValidationException::withMessages(['maintenance' => 'Une maintenance planifiée ne doit pas posséder de bloc véhicule.']);
            }
            if ($from === 'approved') {
                $block = $blocks->first();
                if ($blocks->count() !== 1 || ! $block || $block->tenant_id !== $locked->tenant_id || $block->agency_id !== $locked->agency_id || $block->vehicle_id !== $locked->vehicle_id || $block->block_type->value !== 'maintenance' || $block->status->value !== 'active') {
                    throw ValidationException::withMessages(['maintenance' => 'Le bloc actif de cette maintenance est absent ou incohérent.']);
                }
                $block->forceFill(['status' => 'cancelled', 'released_at' => now()])->save();
            }
            MaintenanceTransition::allow($from, 'cancelled');
            $locked->forceFill(['status' => 'cancelled'])->save();
            MaintenanceStatusHistory::create(['maintenance_order_id' => $locked->id, 'from_status' => $from, 'to_status' => 'cancelled', 'reason' => $reason, 'changed_by' => $actorId]);
            $this->audit->record('maintenance.cancelled', $locked, ['status' => $from], ['status' => 'cancelled']);

            return $locked->refresh();
        });
    }
}
