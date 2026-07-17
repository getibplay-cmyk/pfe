<?php

namespace App\Actions\Finance;

use App\Models\Expense;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectExpense
{
    public function __construct(
        private AgencyAccess $agencies,
        private AuditRecorder $audit,
    ) {}

    public function handle(Expense $expense, string $reason, int $actorId): Expense
    {
        $this->agencies->required($expense->agency_id);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif du rejet est obligatoire.']);
        }

        return DB::transaction(function () use ($expense, $reason, $actorId) {
            $locked = Expense::query()->whereKey($expense)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['expense' => 'Seule une dépense brouillon peut être rejetée.']);
            }

            $locked->forceFill([
                'status' => 'rejected',
                'approved_by' => null,
                'rejected_by' => $actorId,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->record('expense.rejected', $locked, ['status' => 'draft'], ['status' => 'rejected']);

            return $locked->refresh();
        });
    }
}
