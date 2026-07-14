<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\Expense;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class CreateExpense
{
    public function __construct(private GenerateBusinessNumber $numbers, private AuditRecorder $audit) {}

    public function handle(array $data, int $actorId): Expense
    {
        $agencyId = app(TenantContext::class)->agencyId();
        if ($agencyId !== null && $agencyId !== (int) $data['agency_id']) {
            throw ValidationException::withMessages(['agency_id' => 'Cette agence ne fait pas partie du contexte actif.']);
        }
        $amount = DecimalMoney::toMinorUnits($data['amount']);
        $tax = DecimalMoney::toMinorUnits($data['tax_amount'] ?? '0.00');
        if ($amount === 0 || ! in_array($data['category'], ['maintenance', 'insurance', 'fuel', 'cleaning', 'administration', 'other'], true)) {
            throw ValidationException::withMessages(['expense' => 'Dépense invalide.']);
        }
        $expense = Expense::create([
            'agency_id' => $data['agency_id'], 'vehicle_id' => $data['vehicle_id'] ?? null, 'rental_contract_id' => $data['rental_contract_id'] ?? null,
            'maintenance_order_id' => $data['maintenance_order_id'] ?? null, 'expense_number' => $this->numbers->handle('expense'),
            'category' => $data['category'], 'description' => $data['description'], 'amount' => DecimalMoney::fromMinorUnits($amount),
            'tax_amount' => DecimalMoney::fromMinorUnits($tax), 'currency' => $data['currency'] ?? 'MAD', 'expense_date' => $data['expense_date'],
            'supplier' => $data['supplier'] ?? null, 'document_id' => $data['document_id'] ?? null, 'status' => 'draft', 'created_by' => $actorId,
        ]);
        $this->audit->record('expense.created', $expense, [], ['expense_number' => $expense->expense_number, 'amount' => $expense->amount, 'category' => $expense->category]);

        return $expense;
    }
}
