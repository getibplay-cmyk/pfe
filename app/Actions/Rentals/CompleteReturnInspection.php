<?php

namespace App\Actions\Rentals;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\RentalContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\InspectionItem;
use App\Models\RentalContract;
use App\Models\VehicleInspection;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteReturnInspection
{
    public function __construct(private CompareVehicleInspections $compare, private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, array $data, int $actorId): VehicleInspection
    {
        return DB::transaction(function () use ($contract, $data, $actorId) {
            $locked = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::Active) {
                throw ValidationException::withMessages(['status' => 'L’inspection de retour exige un contrat actif.']);
            }
            if ($locked->start_mileage === null || (int) $data['mileage'] < $locked->start_mileage) {
                throw ValidationException::withMessages(['mileage' => 'Le kilométrage retour ne peut être inférieur au départ.']);
            }
            $departure = $locked->inspections()->where('inspection_type', InspectionType::Departure)->where('status', InspectionStatus::Completed)->with('items')->firstOrFail();
            $inspection = VehicleInspection::create(['agency_id' => $locked->agency_id, 'rental_contract_id' => $locked->id, 'vehicle_id' => $locked->vehicle_id, 'inspection_type' => InspectionType::Return, 'status' => InspectionStatus::Draft, 'inspected_at' => $data['inspected_at'] ?? now(), 'mileage' => $data['mileage'], 'fuel_level' => $data['fuel_level'], 'notes' => $data['notes'] ?? null, 'created_by' => $actorId]);
            foreach ($data['items'] ?? [] as $item) {
                InspectionItem::create([...$item, 'vehicle_inspection_id' => $inspection->id]);
            }
            $inspection->forceFill(['status' => InspectionStatus::Completed, 'completed_by' => $actorId, 'completed_at' => now()])->save();
            $inspection->load('items');
            $comparison = $this->compare->handle($departure, $inspection);
            $futureConflicts = $this->compare->futureConflicts($locked, $inspection);
            $locked->forceFill(['status' => RentalContractStatus::ReturnPending, 'return_mileage' => $inspection->mileage, 'return_fuel_level' => $inspection->fuel_level])->save();
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::Active, 'to_status' => RentalContractStatus::ReturnPending, 'reason' => count($comparison['damage_candidates']).' anomalie(s) visuelle(s) à revoir', 'changed_by' => $actorId]);
            $this->audit->record('inspection.return.completed', $inspection, [], ['contract_id' => $locked->id, 'mileage' => $inspection->mileage, 'fuel_level' => $inspection->fuel_level, 'damage_candidates_count' => count($comparison['damage_candidates'])]);
            if ($futureConflicts > 0) {
                $this->audit->record('inspection.return.future_blocks_impacted', $inspection, [], ['contract_id' => $locked->id, 'future_block_conflicts' => $futureConflicts]);
            }

            return $inspection;
        });
    }
}
