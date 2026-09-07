<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\SaasBillingLock;
use App\Support\PlatformBilling\TenantPlanAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestSaasPlanChange
{
    public function handle(User $actor, SaasPlan $plan): SaasSubscription
    {
        abort_unless(config('platform_billing.self_service_enabled'), 404);
        abort_unless($actor->is_active && ! $actor->is_platform_admin && $actor->isTenantOwner()
            && $actor->hasVerifiedEmail() && ($actor->role?->is_active ?? false) && $actor->tenant_id, 403);

        return DB::transaction(function () use ($actor, $plan): SaasSubscription {
            $locks = app(SaasBillingLock::class);
            $locks->activeTenant($actor->tenant_id);
            $current = SaasSubscription::query()->where('tenant_id', $actor->tenant_id)
                ->whereIn('status', collect(TenantSubscriptionStatus::current())->pluck('value'))->lockForUpdate()->first();
            if ($current === null || $current->status === TenantSubscriptionStatus::Suspended) {
                throw ValidationException::withMessages(['subscription' => 'Un abonnement courant non suspendu est nécessaire.']);
            }
            $pending = SaasSubscription::query()->where('tenant_id', $actor->tenant_id)->where('status', 'pending_payment')->first();
            if ($pending !== null) {
                if ($pending->saas_plan_id === $plan->getKey() && $pending->change_expires_at->isFuture()) {
                    return $pending;
                }
                throw ValidationException::withMessages(['saas_plan_id' => 'Annulez la demande en cours avant de changer de formule.']);
            }
            $plan = SaasPlan::query()->whereKey($plan)->lockForUpdate()->firstOrFail();
            if (! $plan->is_active || $plan->currency !== $current->currency || $plan->getKey() === $current->saas_plan_id) {
                throw ValidationException::withMessages(['saas_plan_id' => 'Choisissez une autre formule active dans la même devise.']);
            }
            $locks->ensureNoCheckout($current->getKey());
            if ($current->invoices()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['subscription' => 'Réglez les factures ouvertes avant de changer de formule.']);
            }
            app(TenantPlanAccess::class)->ensureEntitlementsFit($actor->tenant_id, $plan->entitlements);
            $now = CarbonImmutable::now();
            $pending = new SaasSubscription;
            $pending->forceFill([
                'tenant_id' => $actor->tenant_id, 'saas_plan_id' => $plan->getKey(),
                'previous_subscription_id' => $current->getKey(), 'change_expires_at' => $now->addDay(),
                'status' => TenantSubscriptionStatus::PendingPayment,
                'billing_interval' => $plan->billing_interval, 'price_amount' => $plan->price_amount,
                'currency' => $plan->currency, 'entitlements' => $plan->entitlements,
                'starts_at' => $now, 'auto_renew' => $current->auto_renew,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();
            $invoice = app(IssueSaasInvoice::class)->handle($pending, $now, 'plan_change');
            if ($invoice->amount === '0.00') {
                app(SaasInvoiceLifecycle::class)->settle($invoice, null);
            }
            app(AuditRecorder::class)->record('platform.subscription.plan_change_requested', $pending, [], [
                'previous_subscription_id' => $current->getKey(), 'saas_plan_id' => $plan->getKey(),
            ]);

            return $pending->refresh();
        });
    }
}
