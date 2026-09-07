<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\SaasBillingLock;
use Illuminate\Support\Facades\DB;

final class ProcessSaasBilling
{
    public function handle(): int
    {
        $processed = 0;
        SaasSubscription::query()->whereIn('status', ['pending_payment', 'trialing', 'active', 'past_due', 'suspended'])
            ->orderBy('id')->chunkById(100, function ($subscriptions) use (&$processed): void {
                foreach ($subscriptions as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $locks = app(SaasBillingLock::class);
                        $tenant = $locks->tenant($candidate->tenant_id);
                        $subscription = SaasSubscription::query()->whereKey($candidate)->lockForUpdate()->firstOrFail();
                        $locks->expireAttempts($subscription->getKey());
                        if ($subscription->status->isTerminal() || $tenant->status !== 'active' || $tenant->deleted_at !== null) {
                            return;
                        }
                        if ($subscription->status === TenantSubscriptionStatus::PendingPayment) {
                            if ($subscription->change_expires_at->lessThanOrEqualTo(now())) {
                                // Checkout expiry is capped by change expiry, so no active attempt remains.
                                app(CancelSaasPlanChange::class)->handle($subscription);
                            }
                            return;
                        }
                        $openInvoice = $subscription->invoices()->where('status', 'open')->oldest('period_starts_at')->first();
                        if (! config('platform_billing.renewals_enabled') || (! $subscription->auto_renew && $openInvoice === null)
                            || ($subscription->status === TenantSubscriptionStatus::Suspended && ! $subscription->billing_suspended)) {
                            return;
                        }
                        if (SaasSubscription::query()->where('tenant_id', $subscription->tenant_id)->where('status', 'pending_payment')->exists()) {
                            return;
                        }
                        $anchor = $subscription->next_renewal_at ?? $subscription->trial_ends_at ?? $subscription->ends_at;
                        if ($openInvoice === null && ($anchor === null || $anchor->isFuture())) {
                            return;
                        }
                        if ($subscription->paymentAttempts()->where('status', 'pending')->exists()) {
                            return;
                        }
                        $invoice = $openInvoice
                            ?? app(IssueSaasInvoice::class)->handle($subscription, $anchor);
                        if ($invoice->status !== 'open') {
                            return;
                        }
                        if ($invoice->amount === '0.00') {
                            app(SaasInvoiceLifecycle::class)->settle($invoice, null);
                            return;
                        }
                        $suspend = $subscription->status === TenantSubscriptionStatus::Trialing
                            || ($subscription->status === TenantSubscriptionStatus::Suspended && $subscription->billing_suspended)
                            || $invoice->due_at->addDays((int) config('platform_billing.grace_days'))->lessThanOrEqualTo(now());
                        $newStatus = $suspend ? TenantSubscriptionStatus::Suspended : TenantSubscriptionStatus::PastDue;
                        $oldStatus = $subscription->status;
                        if ($oldStatus !== $newStatus) {
                            $subscription->forceFill([
                                'status' => $newStatus, 'suspended_at' => $suspend ? now() : null,
                                'billing_suspended' => $suspend,
                            ])->save();
                            app(AuditRecorder::class)->record('platform.subscription.billing_status_changed', $subscription,
                                ['status' => $oldStatus->value], ['status' => $newStatus->value]);
                        }
                        app(SaasInvoiceLifecycle::class)->event($invoice, 'overdue');
                    });
                    $processed++;
                }
            });

        DB::table('operational_heartbeats')->upsert([
            ['component' => 'saas-billing', 'last_succeeded_at' => now()],
        ], ['component'], ['last_succeeded_at']);

        return $processed;
    }
}
