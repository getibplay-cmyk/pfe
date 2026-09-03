<?php

namespace App\Http\Requests\PlatformBilling;

class ReverseSaasPaymentRequest extends PlatformBillingRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:4000'],
            'cmi_refund_confirmed' => ['nullable', 'accepted'],
            'tenant_id' => ['prohibited'],
            'saas_subscription_id' => ['prohibited'],
            'entry_type' => ['prohibited'],
            'payment_method' => ['prohibited'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'reversal_of_id' => ['prohibited'],
        ];
    }
}
