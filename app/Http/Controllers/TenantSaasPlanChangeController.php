<?php

namespace App\Http\Controllers;

use App\Actions\PlatformBilling\CancelSaasPlanChange;
use App\Actions\PlatformBilling\RequestSaasPlanChange;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\SaasBillingLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TenantSaasPlanChangeController extends Controller
{
    public function store(Request $request, RequestSaasPlanChange $change): RedirectResponse
    {
        $data = $request->validate([
            'saas_plan_id' => ['required', 'integer'], 'terms_accepted' => ['accepted'],
            'tenant_id' => ['prohibited'], 'price_amount' => ['prohibited'], 'currency' => ['prohibited'],
            'status' => ['prohibited'], 'entitlements' => ['prohibited'],
        ]);
        $change->handle($request->user(), SaasPlan::query()->findOrFail($data['saas_plan_id']));

        return redirect()->route('tenant-saas-account.show')->with('status', __('Demande de changement enregistrée. Consultez la facture et son état.'));
    }

    public function destroy(Request $request, SaasSubscription $subscription, CancelSaasPlanChange $cancel): RedirectResponse
    {
        abort_unless($request->user()->isTenantOwner() && ($request->user()->role?->is_active ?? false)
            && $request->user()->tenant_id === $subscription->tenant_id, 404);
        $cancel->handle($subscription);

        return redirect()->route('tenant-saas-account.show')->with('status', __('Changement annulé ; la formule précédente est conservée.'));
    }

    public function renewal(Request $request, SaasSubscription $subscription): RedirectResponse
    {
        abort_unless($request->user()->isTenantOwner() && ($request->user()->role?->is_active ?? false)
            && $request->user()->tenant_id === $subscription->tenant_id, 404);
        $data = $request->validate(['auto_renew' => ['required', 'boolean'], 'terms_accepted' => ['accepted']]);
        $enabled = (bool) $data['auto_renew'];
        abort_if($enabled && ! config('platform_billing.renewals_enabled'), 404);
        DB::transaction(function () use ($subscription, $enabled): void {
            app(SaasBillingLock::class)->activeTenant($subscription->tenant_id);
            $subscription = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
            $anchor = $subscription->next_renewal_at ?? $subscription->trial_ends_at ?? $subscription->ends_at;
            if ($subscription->status->isTerminal() || $subscription->status->value === 'pending_payment'
                || SaasSubscription::query()->where('tenant_id', $subscription->tenant_id)->where('status', 'pending_payment')->exists()
                || ($enabled && ($anchor === null || ! $anchor->isFuture() || $subscription->status->value === 'suspended'))) {
                throw ValidationException::withMessages(['auto_renew' => __('Régularisez la période courante et les changements en cours avant cette opération.')]);
            }
            $old = $subscription->auto_renew;
            $subscription->forceFill(['auto_renew' => $enabled])->save();
            app(AuditRecorder::class)->record('platform.subscription.renewal_consent_changed', $subscription,
                ['auto_renew' => $old], ['auto_renew' => $enabled]);
        });

        return back()->with('status', $enabled ? __('Émission des factures de renouvellement activée, sans prélèvement.') : __('Renouvellement désactivé. Les factures déjà émises restent dues.'));
    }
}
