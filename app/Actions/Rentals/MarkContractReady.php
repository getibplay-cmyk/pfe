<?php

namespace App\Actions\Rentals;

use App\Enums\RentalContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkContractReady
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, int $actorId): RentalContract
    {
        return DB::transaction(function () use ($contract, $actorId) {
            $locked = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::Draft || ! $locked->current_version_id) {
                throw ValidationException::withMessages(['status' => 'Seul un contrat brouillon versionné peut être préparé.']);
            }
            $locked->forceFill(['status' => RentalContractStatus::Ready])->save();
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::Draft, 'to_status' => RentalContractStatus::Ready, 'changed_by' => $actorId]);
            $this->audit->record('contract.ready', $locked, ['status' => 'draft'], ['status' => 'ready']);

            return $locked->refresh();
        });
    }
}
