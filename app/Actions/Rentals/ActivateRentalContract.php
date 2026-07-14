<?php

namespace App\Actions\Rentals;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\ContractStatusHistory;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateRentalContract
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, int $actorId): RentalContract
    {
        return DB::transaction(function () use ($contract, $actorId) {
            $locked = RentalContract::with(['vehicle', 'drivers.driver', 'vehicleBlock'])->whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::Accepted) {
                throw ValidationException::withMessages(['status' => 'Le contrat doit être accepté avant activation.']);
            }
            $inspection = $locked->inspections()->where('inspection_type', InspectionType::Departure)->where('status', InspectionStatus::Completed)->first();
            if (! $inspection) {
                throw ValidationException::withMessages(['inspection' => 'Une inspection de départ terminée est requise.']);
            }
            if ($locked->vehicle->operational_status !== VehicleOperationalStatus::Active) {
                throw ValidationException::withMessages(['vehicle' => 'Le véhicule n’est pas opérationnel.']);
            }
            $driver = $locked->drivers->firstWhere('is_primary', true)?->driver;
            if (! $driver || $driver->licence_expires_at->endOfDay()->lt($locked->expected_start_at)) {
                throw ValidationException::withMessages(['driver' => 'Le permis principal n’est pas valide.']);
            }
            if (! $locked->vehicleBlock || $locked->vehicleBlock->status !== VehicleBlockStatus::Active || $locked->vehicleBlock->rental_contract_id !== $locked->id) {
                throw ValidationException::withMessages(['vehicle_block' => 'Le bloc contractuel actif est requis.']);
            }
            $locked->forceFill(['status' => RentalContractStatus::Active, 'actual_start_at' => $inspection->inspected_at, 'start_mileage' => $inspection->mileage, 'start_fuel_level' => $inspection->fuel_level, 'activated_at' => now()])->save();
            if ($inspection->mileage > $locked->vehicle->current_mileage) {
                $locked->vehicle->forceFill(['current_mileage' => $inspection->mileage])->save();
            }
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::Accepted, 'to_status' => RentalContractStatus::Active, 'changed_by' => $actorId]);
            $this->audit->record('contract.activated', $locked, ['status' => 'accepted'], ['status' => 'active', 'start_mileage' => $inspection->mileage, 'start_fuel_level' => $inspection->fuel_level]);

            return $locked->refresh();
        });
    }
}
