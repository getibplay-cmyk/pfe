<?php

namespace App\Actions\Maintenance;

use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Maintenance\MaintenanceTransition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveMaintenanceOrder
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, int $actorId): MaintenanceOrder
    {
        try {
            return DB::transaction(function () use ($order, $actorId) {
                $locked = MaintenanceOrder::whereKey($order)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'planned' || ! $locked->scheduled_start_at || ! $locked->scheduled_end_at) {
                    throw ValidationException::withMessages(['maintenance' => 'Une maintenance planifiée avec une période valide est requise.']);
                }
                if (VehicleBlock::query()->where('maintenance_order_id', $locked->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['maintenance' => 'Cette maintenance possède déjà un bloc véhicule.']);
                }
                VehicleBlock::create([
                    'agency_id' => $locked->agency_id, 'vehicle_id' => $locked->vehicle_id, 'maintenance_order_id' => $locked->id,
                    'block_type' => 'maintenance', 'starts_at' => $locked->scheduled_start_at, 'ends_at' => $locked->scheduled_end_at,
                    'status' => 'active', 'reason' => $locked->title, 'created_by' => $actorId,
                ]);
                MaintenanceTransition::allow('planned', 'approved');
                $locked->forceFill(['status' => 'approved', 'approved_by' => $actorId])->save();
                MaintenanceStatusHistory::create(['maintenance_order_id' => $locked->id, 'from_status' => 'planned', 'to_status' => 'approved', 'changed_by' => $actorId]);
                $this->audit->record('maintenance.approved', $locked, ['status' => 'planned'], ['status' => 'approved']);

                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages(['schedule' => 'Cette période chevauche une réservation ou un autre bloc actif.']);
            }
            throw $exception;
        }
    }
}
