<?php

namespace App\Actions\Rentals;

use App\Enums\RentalContractStatus;
use App\Enums\VehicleBlockStatus;
use App\Models\ContractStatusHistory;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelDraftRentalContract
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, string $reason, int $actorId): RentalContract
    {
        return DB::transaction(function () use ($contract, $reason, $actorId) {
            $locked = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [RentalContractStatus::Draft, RentalContractStatus::Ready], true)) {
                throw ValidationException::withMessages(['status' => 'Seul un contrat brouillon ou prêt peut être annulé.']);
            }
            $from = $locked->status;
            $locked->forceFill(['status' => RentalContractStatus::Cancelled, 'cancelled_at' => now()])->save();
            $locked->vehicleBlock()->where('status', VehicleBlockStatus::Active)->update(['status' => VehicleBlockStatus::Cancelled->value, 'released_at' => now()]);
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => $from, 'to_status' => RentalContractStatus::Cancelled, 'reason' => $reason, 'changed_by' => $actorId]);
            $this->audit->record('contract.cancelled', $locked, ['status' => $from->value], ['status' => 'cancelled', 'reason' => $reason]);

            return $locked->refresh();
        });
    }
}
