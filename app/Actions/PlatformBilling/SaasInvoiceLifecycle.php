<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasInvoice;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\User;
use App\Notifications\PlatformBilling\SaasInvoiceNotification;
use App\Support\PlatformBilling\SaasBillingLock;
use App\Support\PlatformBilling\TenantPlanAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaasInvoiceLifecycle
{
    public function payable(SaasInvoice $invoice): SaasSubscription
    {
        app(SaasBillingLock::class)->activeTenant($invoice->tenant_id);
        $subscription = $invoice->subscription()->lockForUpdate()->firstOrFail();
        $blocked = $subscription->status->isTerminal()
            || ($subscription->status === TenantSubscriptionStatus::Suspended && ! $subscription->billing_suspended);
        if ($invoice->status !== 'open' || $blocked) {
            throw ValidationException::withMessages(['payment' => __('Cette facture ne peut pas être réglée dans l’état actuel du compte.')]);
        }
        if ($subscription->invoices()->where('status', 'open')->where('period_starts_at', '<', $invoice->period_starts_at)->exists()) {
            throw ValidationException::withMessages(['payment' => __('Réglez d’abord la facture la plus ancienne.')]);
        }
        if ($subscription->status === TenantSubscriptionStatus::PendingPayment) {
            $previous = SaasSubscription::query()->whereKey($subscription->previous_subscription_id)->lockForUpdate()->firstOrFail();
            app(SaasBillingLock::class)->ensureNoCheckout($previous->getKey());
            if ($previous->status->isTerminal() || $previous->status === TenantSubscriptionStatus::Suspended
                || $subscription->change_expires_at->lessThanOrEqualTo(now())
                || $previous->invoices()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['payment' => __('Le changement de formule n’est plus payable. Annulez-le puis renouvelez la demande.')]);
            }
            app(TenantPlanAccess::class)->ensureEntitlementsFit($subscription->tenant_id, $subscription->entitlements);
        }

        return $subscription;
    }

    // Caller holds the tenant lock; payment, invoice and entitlements commit together.
    public function settle(SaasInvoice $invoice, ?SaasPayment $payment): void
    {
        $subscription = $this->payable($invoice);
        if (($invoice->amount !== '0.00' && $payment === null)
            || ($payment !== null && ($payment->saas_invoice_id !== $invoice->getKey()
                || $payment->amount !== $invoice->amount || $payment->currency !== $invoice->currency))) {
            throw new \LogicException(__('Exact invoice settlement required.'));
        }
        if ($subscription->status === TenantSubscriptionStatus::PendingPayment) {
            SaasSubscription::query()->whereKey($subscription->previous_subscription_id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'suspended_at' => null,
                'billing_suspended' => false, 'auto_renew' => false,
            ]);
        }
        $invoice->forceFill(['status' => 'paid', 'paid_at' => now(), 'saas_payment_id' => $payment?->getKey()])->save();
        $end = $invoice->period_ends_at;
        if ($subscription->next_renewal_at?->greaterThan($end)) {
            $end = $subscription->next_renewal_at;
        }
        $covered = $invoice->period_starts_at->lessThanOrEqualTo(now()) && $end->greaterThan(now());
        $subscription->forceFill([
            'status' => $covered ? TenantSubscriptionStatus::Active : TenantSubscriptionStatus::Suspended,
            'billing_suspended' => ! $covered,
            'suspended_at' => $covered ? null : now(),
            'ends_at' => $end,
            'next_renewal_at' => $end,
        ])->save();
        $this->event($invoice, 'paid', (string) ($payment?->getKey() ?? 'free'));
    }

    public function reverse(SaasInvoice $invoice, SaasPayment $reversal): void
    {
        if ($invoice->saas_payment_id !== $reversal->reversal_of_id || $invoice->status !== 'paid') {
            throw ValidationException::withMessages(['payment' => __('Cette facture ne correspond pas au règlement contrepassé.')]);
        }
        $invoice->forceFill([
            'status' => $invoice->type === 'plan_change' ? 'void' : 'open',
            'paid_at' => null, 'saas_payment_id' => null,
        ])->save();
        $subscription = $invoice->subscription()->lockForUpdate()->firstOrFail();
        // Never resurrect a cancelled predecessor or clear an administrative hold.
        if (! $subscription->status->isTerminal()
            && ! ($subscription->status === TenantSubscriptionStatus::Suspended && ! $subscription->billing_suspended)) {
            $subscription->forceFill([
                'status' => TenantSubscriptionStatus::Suspended,
                'suspended_at' => now(), 'billing_suspended' => $invoice->type !== 'plan_change',
                'auto_renew' => $invoice->type === 'plan_change' ? false : $subscription->auto_renew,
            ])->save();
        }
        $this->event($invoice, 'reversed', (string) $reversal->getKey());
    }

    public function event(SaasInvoice $invoice, string $type, string $suffix = ''): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(__('Invoice events and notification jobs require the billing transaction.'));
        }
        $inserted = DB::table('saas_invoice_events')->insertOrIgnore([
            'saas_invoice_id' => $invoice->getKey(), 'type' => $type,
            'event_key' => $invoice->getKey().':'.$type.':'.$suffix, 'created_at' => now(),
        ]);
        if ($inserted !== 1) {
            return;
        }
        User::query()->where('tenant_id', $invoice->tenant_id)->where('is_active', true)
            ->where('is_platform_admin', false)->whereNotNull('email_verified_at')
            ->whereHas('role', fn ($query) => $query->where('slug', 'tenant-owner')->where('is_active', true))
            ->each(fn (User $owner) => $owner->notify(new SaasInvoiceNotification($invoice->getKey(), $type)));
    }
}
