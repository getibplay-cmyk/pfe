<?php

namespace App\Actions\Rentals;

use App\Enums\DamageResponsibility;
use App\Enums\DamageStatus;
use App\Enums\RentalContractStatus;
use App\Models\DamageReport;
use App\Models\DamageStatusHistory;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportVehicleDamage
{
    public function __construct(private GenerateBusinessNumber $numbers, private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, array $data, int $actorId): DamageReport
    {
        return DB::transaction(function () use ($contract, $data, $actorId) {
            $locked = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::ReturnPending) {
                throw ValidationException::withMessages(['status' => 'Un dommage de retour exige un contrat en attente de retour.']);
            }
            $return = $locked->inspections()->where('inspection_type', 'return')->where('status', 'completed')->findOrFail($data['return_inspection_id']);
            $departure = $locked->inspections()->where('inspection_type', 'departure')->where('status', 'completed')->first();
            $damage = DamageReport::create(['agency_id' => $locked->agency_id, 'rental_contract_id' => $locked->id, 'vehicle_id' => $locked->vehicle_id, 'departure_inspection_id' => $departure?->id, 'return_inspection_id' => $return->id, 'damage_number' => $this->numbers->handle('damage', $return->inspected_at->year), 'description' => $data['description'], 'vehicle_area' => $data['vehicle_area'] ?? null, 'severity' => $data['severity'], 'status' => DamageStatus::Reported, 'responsibility' => DamageResponsibility::Pending, 'estimated_cost' => $data['estimated_cost'] ?? '0.00', 'reported_by' => $actorId]);
            DamageStatusHistory::create(['damage_report_id' => $damage->id, 'from_status' => null, 'to_status' => DamageStatus::Reported, 'responsibility' => DamageResponsibility::Pending, 'changed_by' => $actorId]);
            $this->audit->record('damage.reported', $damage, [], ['damage_number' => $damage->damage_number, 'severity' => $damage->severity->value, 'responsibility' => 'pending']);

            return $damage;
        });
    }
}
