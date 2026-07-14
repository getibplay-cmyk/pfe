<?php

namespace App\Actions\Finance;

use App\Models\Invoice;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueInvoice
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Invoice $invoice, int $actorId, mixed $dueAt = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $actorId, $dueAt) {
            $locked = Invoice::with('lines')->whereKey($invoice)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft' || $locked->lines->isEmpty()) {
                throw ValidationException::withMessages(['invoice' => 'Seule une facture brouillon complète peut être émise.']);
            }
            $locked->forceFill(['status' => 'issued', 'issued_at' => now(), 'due_at' => $dueAt, 'issued_by' => $actorId])->save();
            $this->audit->record('invoice.issued', $locked, ['status' => 'draft'], ['status' => 'issued']);

            return $locked->refresh();
        });
    }
}
