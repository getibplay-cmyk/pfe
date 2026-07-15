<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class DepositMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $this->user()?->hasPermission('deposit.create')
            && $contract
            && ($this->user()->agency_id === null || $this->user()->agency_id === $contract->agency_id);
    }

    public function rules(): array
    {
        $reasonRequired = $this->routeIs('finance.deposits.retain');

        return [
            'tenant_id' => ['prohibited'],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }
}
