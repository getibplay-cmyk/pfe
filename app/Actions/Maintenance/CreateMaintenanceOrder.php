<?php

namespace App\Actions\Maintenance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class CreateMaintenanceOrder
{
    public function __construct(private GenerateBusinessNumber $numbers, private AuditRecorder $audit) {}

    public function handle(array $data, int $actorId): MaintenanceOrder
    {
        $agencyId = app(TenantContext::class)->agencyId();
        if ($agencyId !== null && $agencyId !== (int) $data['agency_id']) {
            throw ValidationException::withMessages(['agency_id' => 'Cette agence ne fait pas partie du contexte actif.']);
        }
        if (! in_array($data['maintenance_type'], ['preventive', 'corrective', 'inspection', 'repair'], true) || ! in_array($data['priority'] ?? 'normal', ['low', 'normal', 'high', 'critical'], true)) {
            throw ValidationException::withMessages(['maintenance' => 'Type ou priorité de maintenance invalide.']);
        }
        $estimated = DecimalMoney::toMinorUnits($data['estimated_cost'] ?? '0.00');
        $order = MaintenanceOrder::create([
            'agency_id' => $data['agency_id'], 'vehicle_id' => $data['vehicle_id'], 'maintenance_number' => $this->numbers->handle('maintenance'),
            'maintenance_type' => $data['maintenance_type'], 'priority' => $data['priority'] ?? 'normal', 'status' => 'planned',
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'scheduled_start_at' => $data['scheduled_start_at'] ?? null,
            'scheduled_end_at' => $data['scheduled_end_at'] ?? null, 'mileage_at_opening' => $data['mileage_at_opening'] ?? null,
            'estimated_cost' => DecimalMoney::fromMinorUnits($estimated), 'supplier' => $data['supplier'] ?? null, 'created_by' => $actorId,
        ]);
        MaintenanceStatusHistory::create(['maintenance_order_id' => $order->id, 'from_status' => null, 'to_status' => 'planned', 'changed_by' => $actorId]);
        $this->audit->record('maintenance.created', $order, [], ['maintenance_number' => $order->maintenance_number, 'status' => 'planned']);

        return $order;
    }
}
