<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\SaasBillingLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelSaasPlanChange
{
    public function handle(SaasSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $locks = app(SaasBillingLock::class);
            $locks->tenant($subscription->tenant_id);
            $subscription = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
            if ($subscription->status === TenantSubscriptionStatus::Cancelled) {
                return;
            }
            if ($subscription->status !== TenantSubscriptionStatus::PendingPayment) {
                throw ValidationException::withMessages(['subscription' => 'Seul un changement non réglé peut être annulé.']);
            }
            $locks->ensureNoCheckout($subscription->getKey());
            foreach ($subscription->invoices()->where('status', 'open')->lockForUpdate()->get() as $invoice) {
                $invoice->forceFill(['status' => 'void'])->save();
                app(SaasInvoiceLifecycle::class)->event($invoice, 'voided');
            }
            $subscription->forceFill(['status' => TenantSubscriptionStatus::Cancelled, 'cancelled_at' => now(), 'auto_renew' => false])->save();
            app(AuditRecorder::class)->record('platform.subscription.plan_change_cancelled', $subscription, [], []);
        });
    }
}
