<?php

namespace App\Http\Requests\PlatformBilling;

use App\Enums\IntelligenceCapability;
use Illuminate\Validation\Rule;

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
            'entitlements_configured' => ['sometimes', 'accepted'],
            'max_agencies' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_users' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_vehicles' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'monthly_intelligence_runs' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'intelligence_capabilities' => ['nullable', 'array', 'max:6'],
            'intelligence_capabilities.*' => ['required', Rule::enum(IntelligenceCapability::class), 'distinct'],
            'is_active' => ['required', 'boolean'],
            'code' => ['prohibited'],
            'billing_interval' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }
}
