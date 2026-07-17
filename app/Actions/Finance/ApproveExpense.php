<?php

namespace App\Actions\Finance;

use App\Models\Expense;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveExpense
{
    public function __construct(
        private AuditRecorder $audit,
        private AgencyAccess $agencies,
    ) {}

    public function handle(Expense $expense, int $actorId): Expense
    {
        $this->agencies->required($expense->agency_id);

        return DB::transaction(function () use ($expense, $actorId) {
            $locked = Expense::whereKey($expense)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['expense' => 'Seule une dépense brouillon peut être approuvée.']);
            }
            $locked->forceFill(['status' => 'approved', 'approved_by' => $actorId])->save();
            $this->audit->record('expense.approved', $locked, ['status' => 'draft'], ['status' => 'approved']);

            return $locked->refresh();
        });
    }
}
