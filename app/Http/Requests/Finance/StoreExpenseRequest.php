<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $tenantId = $this->user()->tenant_id;
        $agencyId = $this->integer('agency_id');
        $currency = $this->string('currency')->toString();

        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $tenantId)],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('agency_id', $agencyId))],
            'rental_contract_id' => ['nullable', 'integer', Rule::exists('rental_contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('agency_id', $agencyId)->where('currency', $currency))],
            'maintenance_order_id' => ['nullable', 'integer', Rule::exists('maintenance_orders', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('agency_id', $agencyId))],
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
