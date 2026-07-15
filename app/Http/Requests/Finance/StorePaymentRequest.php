<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agencyId = $this->integer('agency_id');

        return $this->user()?->hasPermission('payment.create')
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
            'rental_contract_id' => ['nullable', 'integer'],
            'customer_id' => ['required', 'integer'],
            'payment_method' => ['required', 'in:cash,card,bank_transfer,cheque,other'],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['required', 'size:3', 'alpha:ascii'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'card_number' => ['prohibited'],
            'pan' => ['prohibited'],
            'cvv' => ['prohibited'],
            'cvc' => ['prohibited'],
        ];
    }
}
