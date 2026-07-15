<?php

namespace App\Http\Requests\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class StoreInsuranceCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = $this->route('policy');

        return $this->user()?->hasPermission('insurance.manage')
            && $policy
            && ($this->user()->agency_id === null || $this->user()->agency_id === $policy->agency_id);
    }

    public function rules(): array
    {
        return [
            'coverage_type' => ['required', 'in:liability,collision,theft,fire,glass,assistance,legal_defence,other'],
            'label' => ['required', 'string', 'max:255'],
            'limit_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'deductible_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'terms' => ['nullable', 'array'],
        ];
    }
}
