<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReversePayment
{
    public function __construct(private GenerateBusinessNumber $numbers, private AuditRecorder $audit, private RecalculateInvoiceBalance $recalculate) {}

    public function handle(Payment $payment, string $idempotencyKey, string $reason, int $actorId): Payment
    {
        return DB::transaction(function () use ($payment, $idempotencyKey, $reason, $actorId) {
            $existing = Payment::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $original = Payment::with('allocations.invoice')->whereKey($payment)->lockForUpdate()->firstOrFail();
            if ($original->status !== 'posted' || $original->reversal_of_id) {
                throw ValidationException::withMessages(['payment' => 'Seul un paiement comptabilisé non inversé peut être contrepassé.']);
            }
            $reversal = Payment::create([
                'agency_id' => $original->agency_id, 'rental_contract_id' => $original->rental_contract_id, 'customer_id' => $original->customer_id,
                'payment_number' => $this->numbers->handle('payment'), 'direction' => $original->direction === 'incoming' ? 'outgoing' : 'incoming',
                'payment_method' => $original->payment_method, 'status' => 'posted', 'amount' => $original->amount, 'currency' => $original->currency,
                'idempotency_key' => $idempotencyKey, 'paid_at' => now(), 'posted_at' => now(), 'reversal_of_id' => $original->id,
                'notes' => $reason, 'created_by' => $actorId, 'posted_by' => $actorId,
            ]);
            foreach ($original->allocations as $allocation) {
                PaymentAllocation::create(['payment_id' => $reversal->id, 'invoice_id' => $allocation->invoice_id, 'amount' => $allocation->amount]);
            }
            $original->forceFill(['status' => 'reversed'])->save();
            foreach ($original->allocations as $allocation) {
                $this->recalculate->handle($allocation->invoice);
            }
            $this->audit->record('payment.reversed', $original, ['status' => 'posted'], ['status' => 'reversed', 'reversal_payment_id' => $reversal->id]);

            return $reversal;
        });
    }
}
