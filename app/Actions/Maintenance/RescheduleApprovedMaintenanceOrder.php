<?php

namespace App\Actions\Maintenance;

use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleApprovedMaintenanceOrder
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, array $data, int $actorId): MaintenanceOrder
    {
        if (! isset($data['scheduled_start_at'], $data['scheduled_end_at'], $data['reason']) || trim((string) $data['reason']) === '') {
            throw ValidationException::withMessages(['schedule' => 'La nouvelle période et son motif sont obligatoires.']);
        }
        $start = CarbonImmutable::parse($data['scheduled_start_at']);
        $end = CarbonImmutable::parse($data['scheduled_end_at']);
        if ($end->lessThanOrEqualTo($start) || $end->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['scheduled_end_at' => 'La fin doit être postérieure au début et située dans le futur.']);
        }

        try {
            return DB::transaction(function () use ($order, $data, $actorId, $start, $end) {
                $locked = MaintenanceOrder::whereKey($order)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, ['planned', 'approved'], true)) {
                    throw ValidationException::withMessages(['maintenance' => 'Seule une maintenance planifiée ou approuvée peut être replanifiée.']);
                }

                $block = null;
                if ($locked->status === 'approved') {
                    $blocks = VehicleBlock::query()->where('maintenance_order_id', $locked->id)->lockForUpdate()->get();
                    if ($blocks->count() !== 1 || ! $this->isCoherentBlock($blocks->first(), $locked)) {
                        throw ValidationException::withMessages(['schedule' => 'Le bloc actif de cette maintenance est absent ou incohérent.']);
                    }
                    $block = $blocks->first();
                }

                $before = [
                    'scheduled_start_at' => $locked->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $locked->scheduled_end_at?->toIso8601String(),
                ];
                $locked->forceFill([
                    'scheduled_start_at' => $start,
                    'scheduled_end_at' => $end,
                ])->save();
                $block?->forceFill([
                    'starts_at' => $start,
                    'ends_at' => $end,
                ])->save();
                MaintenanceStatusHistory::create([
                    'maintenance_order_id' => $locked->id,
                    'from_status' => $locked->status,
                    'to_status' => $locked->status,
                    'reason' => $data['reason'],
                    'changed_by' => $actorId,
                ]);
                $this->audit->record('maintenance.rescheduled', $locked, $before, [
                    'scheduled_start_at' => $locked->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $locked->scheduled_end_at?->toIso8601String(),
                    'reason' => $data['reason'],
                ]);

                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages(['schedule' => 'Cette période chevauche une réservation, un contrat ou un autre bloc actif.']);
            }

            throw $exception;
        }
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
