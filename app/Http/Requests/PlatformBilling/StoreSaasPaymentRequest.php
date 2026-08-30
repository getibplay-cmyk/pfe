<?php

namespace App\Http\Requests\PlatformBilling;

use App\Enums\PlatformBilling\SaasPaymentMethod;
use Illuminate\Validation\Rule;

class StoreSaasPaymentRequest extends PlatformBillingRequest
{
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(SaasPaymentMethod::class)],
            'amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:4000'],
            'tenant_id' => ['prohibited'],
            'saas_subscription_id' => ['prohibited'],
            'entry_type' => ['prohibited'],
            'currency' => ['prohibited'],
            'reversal_of_id' => ['prohibited'],
            'reason' => ['prohibited'],
        ];
    }
}
