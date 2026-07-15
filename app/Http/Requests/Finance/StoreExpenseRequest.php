<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agencyId = $this->integer('agency_id');

        return $this->user()?->hasPermission('expense.create')
            && ($this->user()->agency_id === null || $this->user()->agency_id === $agencyId);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) ($this->input('currency') ?: 'MAD'))]);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['required', 'integer'],
            'vehicle_id' => ['nullable', 'integer'],
            'rental_contract_id' => ['nullable', 'integer'],
            'category' => ['required', 'in:maintenance,insurance,fuel,cleaning,administration,other'],
            'description' => ['required', 'string', 'max:5000'],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'tax_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['required', 'size:3', 'alpha:ascii'],
            'expense_date' => ['required', 'date'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ];
    }
}
