<?php

namespace App\Http\Requests\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class StoreInsurancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agencyId = $this->integer('agency_id');

        return $this->user()?->hasPermission('insurance.manage')
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
            'vehicle_id' => ['required', 'integer'],
            'insurance_company_id' => ['required', 'integer'],
            'policy_number' => ['required', 'string', 'max:255'],
            'policy_type' => ['required', 'in:mandatory_liability,comprehensive,third_party,other'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'premium_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'deductible_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['required', 'size:3', 'alpha:ascii'],
            'status' => ['required', 'in:draft,active,expired,cancelled'],
        ];
    }
}
