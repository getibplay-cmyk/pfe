<?php

namespace App\Http\Requests\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaasSubscriptionRequest extends PlatformBillingRequest
{
    public function rules(): array
    {
        return [
            'saas_plan_id' => ['required', 'integer', Rule::exists('saas_plans', 'id')->where('is_active', true)],
            'status' => ['required', Rule::in([
                TenantSubscriptionStatus::Trialing->value,
                TenantSubscriptionStatus::Active->value,
            ])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'trial_ends_at' => ['nullable', 'required_if:status,trialing', 'date', 'after:starts_at'],
            'next_renewal_at' => ['nullable', 'date', 'after:starts_at'],
            'admin_note' => ['nullable', 'string', 'max:4000'],
            'tenant_id' => ['prohibited'],
            'billing_interval' => ['prohibited'],
            'price_amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'suspended_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'expired_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $endsAt = $this->input('ends_at');
                if ($endsAt === null || $endsAt === '' || $validator->errors()->has('ends_at')) {
                    return;
                }

                $end = CarbonImmutable::parse((string) $endsAt);
                foreach (['trial_ends_at', 'next_renewal_at'] as $field) {
                    $value = $this->input($field);
                    if ($value !== null
                        && $value !== ''
                        && ! $validator->errors()->has($field)
                        && CarbonImmutable::parse((string) $value)->greaterThan($end)) {
                        $validator->errors()->add($field, 'Cette date ne peut pas dépasser la fin prévue de l’abonnement.');
                    }
                }
            },
        ];
    }
}
