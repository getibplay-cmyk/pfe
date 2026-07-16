<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\DepositTransaction;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\DepositLedger;
use App\Support\Finance\FinancialIdempotencyGuard;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordDepositReceipt
{
    public function __construct(
        private GenerateBusinessNumber $numbers,
        private DepositLedger $ledger,
        private AuditRecorder $audit,
        private FinancialIdempotencyGuard $idempotency,
    ) {}

    public function handle(RentalContract $contract, string $amount, string $idempotencyKey, int $actorId, ?int $paymentId = null): DepositTransaction
    {
        $minor = DecimalMoney::toMinorUnits($amount);
        if ($minor === 0) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être positif.']);
        }

        return DB::transaction(function () use ($contract, $minor, $idempotencyKey, $actorId, $paymentId) {
            $locked = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            $this->idempotency->lock($idempotencyKey);
            $existing = DepositTransaction::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                $this->idempotency->assertSameOperation($existing, [
                    'tenant_id' => app(TenantContext::class)->tenantId(),
                    'agency_id' => $locked->agency_id,
                    'rental_contract_id' => $locked->id,
                    'transaction_type' => 'received',
                    'amount' => DecimalMoney::fromMinorUnits($minor),
                    'currency' => $locked->currency,
                    'payment_id' => $paymentId,
                    'related_charge_id' => null,
                    'reversal_of_id' => null,
                    'reason' => null,
                ]);

                return $existing;
            }

            if ($paymentId !== null) {
                $payment = Payment::find($paymentId);
                if (! $payment || $payment->agency_id !== $locked->agency_id || $payment->rental_contract_id !== $locked->id || $payment->customer_id !== $locked->customer_id || trim($payment->currency) !== trim($locked->currency)) {
                    throw ValidationException::withMessages(['payment_id' => 'Le paiement de caution est incompatible avec ce contrat.']);
                }
            }

            $entry = DepositTransaction::create([
                'agency_id' => $locked->agency_id,
                'rental_contract_id' => $locked->id,
                'transaction_number' => $this->numbers->handle('deposit'),
                'transaction_type' => 'received',
                'amount' => DecimalMoney::fromMinorUnits($minor),
                'currency' => $locked->currency,
                'payment_id' => $paymentId,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(),
                'created_by' => $actorId,
            ]);
            $this->ledger->syncContract($locked);
            $this->audit->record('deposit.received', $entry, [], ['amount' => $entry->amount, 'contract_id' => $locked->id]);

            return $entry;
        });
    }
}
