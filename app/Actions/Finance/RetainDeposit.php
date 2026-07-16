<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\ContractCharge;
use App\Models\DepositTransaction;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\DepositLedger;
use App\Support\Finance\FinancialIdempotencyGuard;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetainDeposit
{
    public function __construct(
        private GenerateBusinessNumber $numbers,
        private DepositLedger $ledger,
        private AuditRecorder $audit,
        private FinancialIdempotencyGuard $idempotency,
    ) {}

    public function handle(RentalContract $contract, string $amount, string $idempotencyKey, string $reason, int $actorId, ?int $chargeId = null): DepositTransaction
    {
        $minor = DecimalMoney::toMinorUnits($amount);

        return DB::transaction(function () use ($contract, $minor, $idempotencyKey, $reason, $actorId, $chargeId) {
            $locked = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            $this->idempotency->lock($idempotencyKey);
            if ($existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
                $this->idempotency->assertSameOperation($existing, [
                    'tenant_id' => app(TenantContext::class)->tenantId(),
                    'agency_id' => $locked->agency_id,
                    'rental_contract_id' => $locked->id,
                    'transaction_type' => 'retained',
                    'amount' => DecimalMoney::fromMinorUnits($minor),
                    'currency' => $locked->currency,
                    'payment_id' => null,
                    'related_charge_id' => $chargeId,
                    'reversal_of_id' => null,
                    'reason' => $reason,
                ]);

                return $existing;
            }

            if ($chargeId !== null && ! ContractCharge::where('rental_contract_id', $locked->id)->whereKey($chargeId)->exists()) {
                throw ValidationException::withMessages(['related_charge_id' => 'Le frais associé ne fait pas partie de ce contrat.']);
            }

            if ($minor === 0 || $minor > $this->ledger->totals($locked)['balance']) {
                throw ValidationException::withMessages(['amount' => 'La retenue dépasse le solde de caution.']);
            }

            $entry = DepositTransaction::create([
                'agency_id' => $locked->agency_id,
                'rental_contract_id' => $locked->id,
                'transaction_number' => $this->numbers->handle('deposit'),
                'transaction_type' => 'retained',
                'amount' => DecimalMoney::fromMinorUnits($minor),
                'currency' => $locked->currency,
                'related_charge_id' => $chargeId,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(),
                'reason' => $reason,
                'created_by' => $actorId,
            ]);
            $this->ledger->syncContract($locked);
            $this->audit->record('deposit.retained', $entry, [], ['amount' => $entry->amount, 'contract_id' => $locked->id]);

            return $entry;
        });
    }
}
