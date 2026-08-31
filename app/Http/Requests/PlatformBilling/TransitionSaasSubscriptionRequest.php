<?php

namespace App\Http\Requests\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use Illuminate\Validation\Rule;

class TransitionSaasSubscriptionRequest extends PlatformBillingRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TenantSubscriptionStatus::class)],
            'tenant_id' => ['prohibited'],
            'saas_plan_id' => ['prohibited'],
            'price_amount' => ['prohibited'],
            'currency' => ['prohibited'],
        ];
    }
}
