<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInsuranceClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agencyId = $this->integer('agency_id');

        return $this->user()?->hasPermission('claim.manage')
            && ($this->user()->agency_id === null || $this->user()->agency_id === $agencyId);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['required', 'integer'],
            'insurance_policy_id' => ['required', 'integer'],
            'damage_report_id' => ['nullable', 'integer'],
            'rental_contract_id' => ['nullable', 'integer'],
            'status' => ['prohibited'],
            'incident_at' => ['required', 'date'],
            'reported_at' => ['nullable', 'date'],
            'claimed_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'approved_amount' => ['prohibited'],
            'settled_amount' => ['prohibited'],
            'insurer_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
