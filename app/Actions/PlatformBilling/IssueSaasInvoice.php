<?php

namespace App\Actions\PlatformBilling;

use App\Models\PlatformBilling\SaasInvoice;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\PlatformBilling\SaasBillingLock;
use App\Support\PlatformBilling\SaasBillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IssueSaasInvoice
{
    public function handle(SaasSubscription $subscription, CarbonImmutable $start, string $type = 'renewal'): SaasInvoice
    {
        return DB::transaction(function () use ($subscription, $start, $type): SaasInvoice {
            app(SaasBillingLock::class)->activeTenant($subscription->tenant_id);
            $subscription = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
            $existing = $subscription->invoices()->where('period_starts_at', $start)->first();
            if ($existing !== null) {
                return $existing;
            }

            $invoice = new SaasInvoice;
            $invoice->forceFill([
                'tenant_id' => $subscription->tenant_id,
                'saas_subscription_id' => $subscription->getKey(),
                'number' => 'BS-'.strtoupper((string) Str::ulid()),
                'type' => $type,
                'status' => 'open',
                'amount' => $subscription->price_amount,
                'currency' => $subscription->currency,
                'snapshot' => [
                    'issuer' => (string) config('platform_billing.invoice_issuer'),
                    'customer' => $subscription->tenant->name,
                    'plan' => $subscription->plan->name,
                    'billing_interval' => $subscription->billing_interval->value,
                    'entitlements' => $subscription->entitlements,
                ],
                'period_starts_at' => $start,
                'period_ends_at' => app(SaasBillingPeriod::class)->end($subscription, $start),
                'due_at' => $start,
            ])->save();
            app(SaasInvoiceLifecycle::class)->event($invoice, 'issued');

            return $invoice;
        });
    }
}
