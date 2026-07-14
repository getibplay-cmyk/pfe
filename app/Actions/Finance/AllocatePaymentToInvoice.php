<?php

namespace App\Actions\Finance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AllocatePaymentToInvoice
{
    public function __construct(private RecalculateInvoiceBalance $recalculate) {}

    public function handle(Payment $payment, Invoice $invoice, string $amount): PaymentAllocation
    {
        $amountMinor = DecimalMoney::toMinorUnits($amount);
        if ($amountMinor === 0) {
            throw ValidationException::withMessages(['amount' => 'Le montant alloué doit être positif.']);
        }

        return DB::transaction(function () use ($payment, $invoice, $amountMinor) {
            $lockedPayment = Payment::whereKey($payment)->lockForUpdate()->firstOrFail();
            $lockedInvoice = Invoice::whereKey($invoice)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedPayment->status, ['pending', 'posted'], true) || $lockedPayment->direction !== 'incoming') {
                throw ValidationException::withMessages(['payment' => 'Le paiement entrant doit être en attente ou comptabilisé.']);
            }
            if (! in_array($lockedInvoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['invoice' => 'La facture ne peut plus recevoir de règlement.']);
            }
            if ($lockedPayment->customer_id !== $lockedInvoice->customer_id || $lockedPayment->currency !== $lockedInvoice->currency) {
                throw ValidationException::withMessages(['allocation' => 'Client ou devise incompatible.']);
            }
            $used = PaymentAllocation::where('payment_id', $lockedPayment->id)->sum('amount');
            if (DecimalMoney::toMinorUnits((string) $used) + $amountMinor > DecimalMoney::toMinorUnits($lockedPayment->amount)) {
                throw ValidationException::withMessages(['amount' => 'Le montant dépasse le disponible du paiement.']);
            }
            $scheduled = DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')
                ->where('a.invoice_id', $lockedInvoice->id)->whereIn('p.status', ['pending', 'posted', 'reversed'])
                ->selectRaw("COALESCE(SUM(CASE WHEN p.direction = 'incoming' THEN a.amount ELSE -a.amount END), 0) AS amount")->value('amount');
            if (DecimalMoney::toMinorUnits((string) $scheduled) + $amountMinor > DecimalMoney::toMinorUnits($lockedInvoice->total_amount)) {
                throw ValidationException::withMessages(['amount' => 'Le montant dépasse le solde disponible de la facture.']);
            }

            $allocation = PaymentAllocation::create(['payment_id' => $lockedPayment->id, 'invoice_id' => $lockedInvoice->id, 'amount' => DecimalMoney::fromMinorUnits($amountMinor)]);
            if ($lockedPayment->status === 'posted') {
                $this->recalculate->handle($lockedInvoice);
            }

            return $allocation;
        });
    }
}
