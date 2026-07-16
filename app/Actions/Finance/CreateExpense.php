<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\Expense;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Vehicle;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Validation\ValidationException;

class CreateExpense
{
    public function __construct(
        private GenerateBusinessNumber $numbers,
        private AuditRecorder $audit,
        private AgencyAccess $agencies,
    ) {}

    public function handle(array $data, int $actorId): Expense
    {
        $agencyId = $this->agencies->required($data['agency_id'] ?? null);
        $currency = strtoupper($data['currency'] ?? 'MAD');

        if (! empty($data['vehicle_id']) && ! Vehicle::whereKey($data['vehicle_id'])->where('agency_id', $agencyId)->exists()) {
            throw ValidationException::withMessages(['vehicle_id' => 'Le véhicule doit appartenir à l’agence de la dépense.']);
        }

        if (! empty($data['rental_contract_id']) && ! RentalContract::whereKey($data['rental_contract_id'])->where('agency_id', $agencyId)->where('currency', $currency)->exists()) {
            throw ValidationException::withMessages(['rental_contract_id' => 'Le contrat doit appartenir à cette agence et utiliser la même devise.']);
        }

        if (! empty($data['maintenance_order_id']) && ! MaintenanceOrder::whereKey($data['maintenance_order_id'])->where('agency_id', $agencyId)->exists()) {
            throw ValidationException::withMessages(['maintenance_order_id' => 'La maintenance doit appartenir à l’agence de la dépense.']);
        }

        $amount = DecimalMoney::toMinorUnits($data['amount']);
        $tax = DecimalMoney::toMinorUnits($data['tax_amount'] ?? '0.00');
        if ($amount === 0 || ! in_array($data['category'], ['maintenance', 'insurance', 'fuel', 'cleaning', 'administration', 'other'], true)) {
            throw ValidationException::withMessages(['expense' => 'Dépense invalide.']);
        }

        $expense = Expense::create([
            'agency_id' => $agencyId,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'rental_contract_id' => $data['rental_contract_id'] ?? null,
            'maintenance_order_id' => $data['maintenance_order_id'] ?? null,
            'expense_number' => $this->numbers->handle('expense'),
            'category' => $data['category'],
            'description' => $data['description'],
            'amount' => DecimalMoney::fromMinorUnits($amount),
            'tax_amount' => DecimalMoney::fromMinorUnits($tax),
            'currency' => $currency,
            'expense_date' => $data['expense_date'],
            'supplier' => $data['supplier'] ?? null,
            'document_id' => $data['document_id'] ?? null,
            'status' => 'draft',
            'created_by' => $actorId,
        ]);
        $this->audit->record('expense.created', $expense, [], ['expense_number' => $expense->expense_number, 'amount' => $expense->amount, 'category' => $expense->category]);

        return $expense;
    }
}
