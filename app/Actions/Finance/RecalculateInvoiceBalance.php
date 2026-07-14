<?php

namespace App\Actions\Finance;

use App\Models\Invoice;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecalculateInvoiceBalance
{
    public function handle(Invoice $invoice): Invoice
    {
        $locked = Invoice::whereKey($invoice)->lockForUpdate()->firstOrFail();
        $row = DB::table('payment_allocations as a')
            ->join('payments as p', fn ($join) => $join->on('p.id', '=', 'a.payment_id')->on('p.tenant_id', '=', 'a.tenant_id'))
            ->where('a.invoice_id', $locked->id)->whereIn('p.status', ['posted', 'reversed'])
            ->selectRaw("COALESCE(SUM(CASE WHEN p.direction = 'incoming' THEN a.amount ELSE -a.amount END), 0) AS paid")
            ->first();
        $paid = DecimalMoney::toMinorUnits((string) $row->paid);
        $total = DecimalMoney::toMinorUnits($locked->total_amount);
        if ($paid < 0 || $paid > $total) {
            throw ValidationException::withMessages(['allocation' => 'Le total alloué est incohérent.']);
        }
        $status = $paid === 0 ? 'issued' : ($paid === $total ? 'paid' : 'partially_paid');
        $locked->forceFill(['paid_amount' => DecimalMoney::fromMinorUnits($paid), 'balance_due' => DecimalMoney::fromMinorUnits($total - $paid), 'status' => $status])->save();
        $locked->rentalContract()->update(['amount_paid' => $locked->paid_amount, 'balance_due' => $locked->balance_due]);

        return $locked->refresh();
    }
}
