<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $this->user()?->hasPermission('invoice.create')
            && $contract
            && ($this->user()->agency_id === null || $this->user()->agency_id === $contract->agency_id);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'tax_mode' => ['nullable', 'in:none,inclusive,exclusive'],
            'tax_rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
        ];
    }
}
