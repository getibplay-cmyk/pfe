<?php

namespace App\Actions\Rentals;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\RentalContractStatus;
use App\Models\InspectionItem;
use App\Models\RentalContract;
use App\Models\VehicleInspection;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteDepartureInspection
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, array $data, int $actorId): VehicleInspection
    {
        return DB::transaction(function () use ($contract, $data, $actorId) {
            $locked = RentalContract::with('vehicle')->whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::Accepted) {
                throw ValidationException::withMessages(['status' => 'L’inspection de départ exige un contrat accepté.']);
            }
            if ((int) $data['mileage'] < $locked->vehicle->current_mileage) {
                throw ValidationException::withMessages(['mileage' => 'Le kilométrage de départ ne peut être inférieur au kilométrage courant.']);
            }
            $inspection = VehicleInspection::create(['agency_id' => $locked->agency_id, 'rental_contract_id' => $locked->id, 'vehicle_id' => $locked->vehicle_id, 'inspection_type' => InspectionType::Departure, 'status' => InspectionStatus::Draft, 'inspected_at' => $data['inspected_at'] ?? now(), 'mileage' => $data['mileage'], 'fuel_level' => $data['fuel_level'], 'notes' => $data['notes'] ?? null, 'created_by' => $actorId]);
            foreach ($data['items'] ?? [] as $item) {
                InspectionItem::create([...$item, 'vehicle_inspection_id' => $inspection->id]);
            }
            $inspection->forceFill(['status' => InspectionStatus::Completed, 'completed_by' => $actorId, 'completed_at' => now()])->save();
            $this->audit->record('inspection.departure.completed', $inspection, [], ['contract_id' => $locked->id, 'mileage' => $inspection->mileage, 'fuel_level' => $inspection->fuel_level]);

            return $inspection->load('items');
        });
    }
}
