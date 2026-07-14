<?php

namespace App\Actions\Finance;

use App\Models\Invoice;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidInvoice
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $locked = Invoice::whereKey($invoice)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'issued' || $locked->paid_amount !== '0.00' || $locked->allocations()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Seule une facture émise sans allocation peut être annulée.']);
            }
            $contract = RentalContract::whereKey($locked->rental_contract_id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => 'void'])->save();
            $contract->forceFill(['invoice_id' => null, 'amount_paid' => '0.00', 'balance_due' => '0.00'])->save();
            $this->audit->record('invoice.voided', $locked, ['status' => 'issued'], ['status' => 'void', 'reason' => $reason]);

            return $locked->refresh();
        });
    }
}
