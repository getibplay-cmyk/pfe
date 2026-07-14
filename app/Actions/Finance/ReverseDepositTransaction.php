<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\DepositTransaction;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\DepositLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseDepositTransaction
{
    public function __construct(private GenerateBusinessNumber $numbers, private DepositLedger $ledger, private AuditRecorder $audit) {}

    public function handle(DepositTransaction $transaction, string $idempotencyKey, string $reason, int $actorId): DepositTransaction
    {
        return DB::transaction(function () use ($transaction, $idempotencyKey, $reason, $actorId) {
            if ($existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
                return $existing;
            }
            $original = DepositTransaction::whereKey($transaction)->lockForUpdate()->firstOrFail();
            if ($original->transaction_type === 'reversal' || DepositTransaction::where('reversal_of_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Ce mouvement ne peut pas être contrepassé.']);
            }
            $contract = RentalContract::whereKey($original->rental_contract_id)->lockForUpdate()->firstOrFail();
            $reversal = DepositTransaction::create([
                'agency_id' => $original->agency_id, 'rental_contract_id' => $original->rental_contract_id,
                'transaction_number' => $this->numbers->handle('deposit'), 'transaction_type' => 'reversal', 'amount' => $original->amount,
                'currency' => $original->currency, 'reversal_of_id' => $original->id, 'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(), 'reason' => $reason, 'created_by' => $actorId,
            ]);
            $this->ledger->syncContract($contract);
            $this->audit->record('deposit.reversed', $reversal, [], ['original_transaction_id' => $original->id, 'amount' => $reversal->amount]);

            return $reversal;
        });
    }
}
