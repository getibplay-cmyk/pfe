<?php

namespace App\Actions\Finance;

use App\Enums\ContractChargeStatus;
use App\Enums\RentalContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\DepositLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseRentalContract
{
    public function __construct(private DepositLedger $ledger, private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, int $actorId): RentalContract
    {
        return DB::transaction(function () use ($contract, $actorId) {
            $locked = RentalContract::with('invoice')->whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::Returned) {
                throw ValidationException::withMessages(['status' => 'Seul un contrat retourné peut être clôturé.']);
            }
            if ($locked->charges()->where('status', ContractChargeStatus::Proposed)->exists()) {
                throw ValidationException::withMessages(['charges' => 'Tous les frais doivent être revus.']);
            }
            if (! $locked->invoice || $locked->invoice->status !== 'paid' || $locked->invoice->balance_due !== '0.00') {
                throw ValidationException::withMessages(['invoice' => 'La facture émise doit être intégralement réglée.']);
            }
            $totals = $this->ledger->syncContract($locked);
            if ($totals['balance'] !== 0) {
                throw ValidationException::withMessages(['deposit' => 'La caution doit être entièrement retenue ou remboursée.']);
            }
            if ($locked->payments()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['payments' => 'Un paiement est encore en attente.']);
            }

            $now = now();
            $locked->forceFill(['status' => RentalContractStatus::Closed, 'amount_paid' => $locked->invoice->paid_amount, 'balance_due' => '0.00', 'financially_settled_at' => $now, 'closed_at' => $now, 'closed_by' => $actorId])->save();
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::Returned, 'to_status' => RentalContractStatus::Closed, 'reason' => 'Clôture financière', 'changed_by' => $actorId]);
            $this->audit->record('contract.closed', $locked, ['status' => 'returned'], ['status' => 'closed', 'invoice_id' => $locked->invoice_id]);

            return $locked->refresh();
        });
    }
}
