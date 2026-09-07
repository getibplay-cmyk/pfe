<?php

namespace App\Http\Requests\PlatformBilling;

use App\Enums\IntelligenceCapability;
use App\Enums\PlatformBilling\SaasBillingInterval;
use Illuminate\Validation\Rule;

class StoreSaasPlanRequest extends PlatformBillingRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]{1,49}$/', Rule::unique('saas_plans', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'billing_interval' => ['required', Rule::enum(SaasBillingInterval::class)],
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
            'tenant_id' => ['prohibited'],
        ];
    }
}
