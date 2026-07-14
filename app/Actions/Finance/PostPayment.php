<?php

namespace App\Actions\Finance;

use App\Models\Payment;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostPayment
{
    public function __construct(private AuditRecorder $audit, private RecalculateInvoiceBalance $recalculate) {}

    public function handle(Payment $payment, int $actorId): Payment
    {
        return DB::transaction(function () use ($payment, $actorId) {
            $locked = Payment::with('allocations.invoice')->whereKey($payment)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'posted') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Seul un paiement en attente peut être comptabilisé.']);
            }
            $locked->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $actorId])->save();
            foreach ($locked->allocations as $allocation) {
                $this->recalculate->handle($allocation->invoice);
            }
            $this->audit->record('payment.posted', $locked, ['status' => 'pending'], ['status' => 'posted']);

            return $locked->refresh();
        });
    }
}
