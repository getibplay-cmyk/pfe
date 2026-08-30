<?php

namespace App\Http\Requests\PlatformBilling;

class UpdateSaasPlanRequest extends PlatformBillingRequest
{
    protected $errorBag = 'updatePlan';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'price_amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'features' => ['required', 'array', 'max:30'],
            'features.*' => ['required', 'string', 'max:160', 'distinct'],
            'is_active' => ['required', 'boolean'],
            'code' => ['prohibited'],
            'billing_interval' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }
}
