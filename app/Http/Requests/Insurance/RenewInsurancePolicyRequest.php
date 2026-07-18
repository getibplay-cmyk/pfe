<?php

namespace App\Http\Requests\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class RenewInsurancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('renew', $this->route('policy')) ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'status' => ['prohibited'],
            'policy_number' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'premium_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'deductible_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'copy_coverages' => ['nullable', 'boolean'],
        ];
    }
}
