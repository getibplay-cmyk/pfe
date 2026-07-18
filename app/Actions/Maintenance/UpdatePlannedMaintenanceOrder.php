<?php

namespace App\Actions\Maintenance;

use App\Models\MaintenanceOrder;
use App\Models\Vehicle;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class UpdatePlannedMaintenanceOrder
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(MaintenanceOrder $order, array $data): MaintenanceOrder
    {
        $required = ['vehicle_id', 'maintenance_type', 'priority', 'title', 'description', 'scheduled_start_at', 'scheduled_end_at', 'mileage_at_opening', 'estimated_cost', 'supplier'];
        if (collect($required)->contains(fn (string $field) => ! array_key_exists($field, $data))) {
            throw ValidationException::withMessages(['maintenance' => 'La modification doit transmettre tous les champs éditables.']);
        }
        if (! in_array($data['maintenance_type'], ['preventive', 'corrective', 'inspection', 'repair'], true) || ! in_array($data['priority'], ['low', 'normal', 'high', 'critical'], true)) {
            throw ValidationException::withMessages(['maintenance' => 'Type ou priorité de maintenance invalide.']);
        }

        try {
            $start = CarbonImmutable::parse($data['scheduled_start_at']);
            $end = CarbonImmutable::parse($data['scheduled_end_at']);
            $estimatedCost = DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['estimated_cost']));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['maintenance' => 'La période ou le coût estimé est invalide.']);
        }
        if ($end->lessThanOrEqualTo($start) || $end->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['scheduled_end_at' => 'La fin doit être postérieure au début et située dans le futur.']);
        }

        return DB::transaction(function () use ($order, $data, $estimatedCost, $start, $end) {
            $locked = MaintenanceOrder::whereKey($order)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'planned') {
                throw ValidationException::withMessages(['maintenance' => 'Seule une maintenance planifiée peut être modifiée.']);
            }

            $vehicle = Vehicle::query()->whereKey($data['vehicle_id'])->where('agency_id', $locked->agency_id)->first();
            if (! $vehicle) {
                throw ValidationException::withMessages(['vehicle_id' => 'Le véhicule doit appartenir à l’agence de maintenance.']);
            }

            $before = $this->auditValues($locked);
            $locked->forceFill([
                'vehicle_id' => $vehicle->id,
                'maintenance_type' => $data['maintenance_type'],
                'priority' => $data['priority'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'mileage_at_opening' => $data['mileage_at_opening'] ?? null,
                'estimated_cost' => $estimatedCost,
                'supplier' => $data['supplier'] ?? null,
            ])->save();
            $this->audit->record('maintenance.updated', $locked, $before, $this->auditValues($locked));

            return $locked->refresh();
        });
    }

    private function auditValues(MaintenanceOrder $order): array
    {
        return [
            'vehicle_id' => $order->vehicle_id,
            'maintenance_type' => $order->maintenance_type,
            'priority' => $order->priority,
            'scheduled_start_at' => $order->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $order->scheduled_end_at?->toIso8601String(),
            'mileage_at_opening' => $order->mileage_at_opening,
            'estimated_cost' => $order->estimated_cost,
        ];
    }
}
