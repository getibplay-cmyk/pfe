<?php

namespace App\Actions\Maintenance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\MaintenanceOrder;
use App\Models\MaintenanceStatusHistory;
use App\Models\Vehicle;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\AgencyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateMaintenanceOrder
{
    public function __construct(
        private GenerateBusinessNumber $numbers,
        private AuditRecorder $audit,
        private AgencyAccess $agencies,
    ) {}

    public function handle(array $data, int $actorId): MaintenanceOrder
    {
        $agencyId = $this->agencies->required($data['agency_id'] ?? null);
        if (! Vehicle::whereKey($data['vehicle_id'] ?? null)->where('agency_id', $agencyId)->exists()) {
            throw ValidationException::withMessages(['vehicle_id' => 'Le véhicule doit appartenir à l’agence de maintenance.']);
        }

        if (! in_array($data['maintenance_type'], ['preventive', 'corrective', 'inspection', 'repair'], true) || ! in_array($data['priority'] ?? 'normal', ['low', 'normal', 'high', 'critical'], true)) {
            throw ValidationException::withMessages(['maintenance' => 'Type ou priorité de maintenance invalide.']);
        }

        try {
            $estimated = DecimalMoney::toMinorUnits($data['estimated_cost'] ?? '0.00');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['estimated_cost' => 'Le coût estimé doit être un montant décimal positif ou nul.']);
        }
        $start = isset($data['scheduled_start_at']) ? CarbonImmutable::parse($data['scheduled_start_at']) : null;
        $end = isset($data['scheduled_end_at']) ? CarbonImmutable::parse($data['scheduled_end_at']) : null;
        if (($start === null) !== ($end === null) || ($start && $end && ($end->lessThanOrEqualTo($start) || $end->lessThanOrEqualTo(now())))) {
            throw ValidationException::withMessages(['scheduled_end_at' => 'La période doit être complète, ordonnée et se terminer dans le futur.']);
        }

        return DB::transaction(function () use ($agencyId, $data, $actorId, $estimated, $start, $end) {
            $order = MaintenanceOrder::create([
                'agency_id' => $agencyId,
                'vehicle_id' => $data['vehicle_id'],
                'maintenance_number' => $this->numbers->handle('maintenance'),
                'maintenance_type' => $data['maintenance_type'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'planned',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'mileage_at_opening' => $data['mileage_at_opening'] ?? null,
                'estimated_cost' => DecimalMoney::fromMinorUnits($estimated),
                'supplier' => $data['supplier'] ?? null,
                'created_by' => $actorId,
            ]);
            MaintenanceStatusHistory::create(['maintenance_order_id' => $order->id, 'from_status' => null, 'to_status' => 'planned', 'changed_by' => $actorId]);
            $this->audit->record('maintenance.created', $order, [], ['maintenance_number' => $order->maintenance_number, 'status' => 'planned']);

            return $order;
        });
    }
}
