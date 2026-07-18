<?php

namespace App\Http\Requests\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInsurancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('policy')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) ($this->input('currency') ?: 'MAD'))]);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'status' => ['prohibited'],
            'vehicle_id' => ['required', 'integer'], 'insurance_company_id' => ['required', 'integer'],
            'policy_number' => ['nullable', 'string', 'max:255'],
            'policy_type' => ['required', 'in:mandatory_liability,comprehensive,third_party,other'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'premium_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'], 'deductible_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['required', 'size:3', 'alpha:ascii'],
        ];
    }
}
