<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\DepositTransaction;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\DepositLedger;
use App\Support\Finance\FinancialIdempotencyGuard;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundDeposit
{
    public function __construct(
        private GenerateBusinessNumber $numbers,
        private DepositLedger $ledger,
        private AuditRecorder $audit,
        private FinancialIdempotencyGuard $idempotency,
    ) {}

    public function handle(RentalContract $contract, string $amount, string $idempotencyKey, int $actorId, ?string $reason = null): DepositTransaction
    {
        $minor = DecimalMoney::toMinorUnits($amount);

        return DB::transaction(function () use ($contract, $minor, $idempotencyKey, $actorId, $reason) {
            $locked = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            $this->idempotency->lock($idempotencyKey);
            if ($existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
                $this->idempotency->assertSameOperation($existing, [
                    'tenant_id' => app(TenantContext::class)->tenantId(),
                    'agency_id' => $locked->agency_id,
                    'rental_contract_id' => $locked->id,
                    'transaction_type' => 'refunded',
                    'amount' => DecimalMoney::fromMinorUnits($minor),
                    'currency' => $locked->currency,
                    'payment_id' => null,
                    'related_charge_id' => null,
                    'reversal_of_id' => null,
                    'reason' => $reason,
                ]);

                return $existing;
            }

            if ($minor === 0 || $minor > $this->ledger->totals($locked)['balance']) {
                throw ValidationException::withMessages(['amount' => 'Le remboursement dépasse le solde de caution.']);
            }

            $entry = DepositTransaction::create([
                'agency_id' => $locked->agency_id,
                'rental_contract_id' => $locked->id,
                'transaction_number' => $this->numbers->handle('deposit'),
                'transaction_type' => 'refunded',
                'amount' => DecimalMoney::fromMinorUnits($minor),
                'currency' => $locked->currency,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(),
                'reason' => $reason,
                'created_by' => $actorId,
            ]);
            $this->ledger->syncContract($locked);
            $this->audit->record('deposit.refunded', $entry, [], ['amount' => $entry->amount, 'contract_id' => $locked->id]);

            return $entry;
        });
    }
}
