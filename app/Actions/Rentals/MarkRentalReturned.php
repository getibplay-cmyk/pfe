<?php

namespace App\Actions\Rentals;

use App\Enums\ContractChargeStatus;
use App\Enums\DamageResponsibility;
use App\Enums\DamageStatus;
use App\Enums\RentalContractStatus;
use App\Enums\VehicleBlockStatus;
use App\Models\ContractStatusHistory;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkRentalReturned
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, array $decisions, int $actorId): RentalContract
    {
        return DB::transaction(function () use ($contract, $decisions, $actorId) {
            $locked = RentalContract::with(['damages', 'charges', 'inspections'])->whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::ReturnPending) {
                throw ValidationException::withMessages(['status' => 'Seul un retour en attente peut être finalisé.']);
            }
            if (! $locked->inspections->contains(fn ($inspection) => $inspection->inspection_type->value === 'return' && $inspection->status->value === 'completed')) {
                throw ValidationException::withMessages(['inspection' => 'Une inspection de retour terminée est requise.']);
            }
            if ($locked->damages->contains(fn ($damage) => in_array($damage->status, [DamageStatus::Reported, DamageStatus::UnderReview], true) || $damage->responsibility === DamageResponsibility::Pending)) {
                throw ValidationException::withMessages(['damages' => 'Chaque dommage doit faire l’objet d’une revue humaine finale.']);
            }

            $approved = collect($decisions['approved_charge_ids'] ?? [])->map(fn ($id) => (int) $id);
            $rejected = collect($decisions['rejected_charge_ids'] ?? [])->map(fn ($id) => (int) $id);
            $proposed = $locked->charges->where('status', ContractChargeStatus::Proposed)->pluck('id');
            if ($approved->intersect($rejected)->isNotEmpty() || $proposed->diff($approved->merge($rejected))->isNotEmpty()) {
                throw ValidationException::withMessages(['charges' => 'Chaque frais proposé doit être explicitement approuvé ou rejeté.']);
            }

            $locked->charges()->whereIn('id', $approved)->update(['status' => ContractChargeStatus::Approved->value, 'approved_by' => $actorId, 'approved_at' => now()]);
            $locked->charges()->whereIn('id', $rejected)->update(['status' => ContractChargeStatus::Rejected->value, 'approved_by' => $actorId, 'approved_at' => now()]);
            $additional = $locked->charges()->where('status', ContractChargeStatus::Approved)->get()->sum(fn ($charge) => DecimalMoney::toMinorUnits($charge->total_amount));
            $subtotal = DecimalMoney::toMinorUnits($locked->rental_subtotal);
            $returnedAt = $locked->inspections->first(fn ($inspection) => $inspection->inspection_type->value === 'return')?->inspected_at ?? now();

            $locked->forceFill([
                'status' => RentalContractStatus::Returned,
                'actual_return_at' => $returnedAt,
                'returned_at' => now(),
                'additional_charges_total' => DecimalMoney::fromMinorUnits($additional),
                'total_amount' => DecimalMoney::fromMinorUnits($subtotal + $additional),
            ])->save();
            $locked->vehicle()->update(['current_mileage' => $locked->return_mileage]);
            $locked->vehicleBlock()->where('status', VehicleBlockStatus::Active)->update(['status' => VehicleBlockStatus::Released->value, 'released_at' => now()]);
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::ReturnPending, 'to_status' => RentalContractStatus::Returned, 'reason' => $decisions['reason'] ?? null, 'changed_by' => $actorId]);
            $this->audit->record('contract.returned', $locked, ['status' => 'return_pending'], ['status' => 'returned', 'approved_charge_count' => $approved->count(), 'rejected_charge_count' => $rejected->count(), 'additional_charges_total' => $locked->additional_charges_total]);

            return $locked->refresh();
        });
    }
}
