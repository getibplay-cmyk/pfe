<?php

namespace App\Actions\Maintenance;

use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelMaintenanceOrder
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, string $reason, int $actorId): MaintenanceOrder
    {
        return DB::transaction(function () use ($order, $reason, $actorId) {
            $locked = MaintenanceOrder::with('vehicleBlock')->whereKey($order)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['planned', 'approved'], true)) {
                throw ValidationException::withMessages(['maintenance' => 'Cette maintenance ne peut plus être annulée.']);
            }
            $from = $locked->status;
            $locked->vehicleBlock?->forceFill(['status' => 'cancelled', 'released_at' => now()])->save();
            $locked->forceFill(['status' => 'cancelled'])->save();
            MaintenanceStatusHistory::create(['maintenance_order_id' => $locked->id, 'from_status' => $from, 'to_status' => 'cancelled', 'reason' => $reason, 'changed_by' => $actorId]);
            $this->audit->record('maintenance.cancelled', $locked, ['status' => $from], ['status' => 'cancelled']);

            return $locked->refresh();
        });
    }
}
