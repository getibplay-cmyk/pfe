<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InsuranceClaimTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $claim = $this->route('claim');

        return $this->user()?->hasPermission('claim.manage')
            && $claim
            && ($this->user()->agency_id === null || $this->user()->agency_id === $claim->agency_id);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'status' => ['prohibited'],
            'approved_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'settled_amount' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
