<?php

namespace App\Http\Controllers;

use App\Actions\PlatformBilling\StartCmiCheckout;
use App\Enums\PlatformBilling\SaasPaymentAttemptStatus;
use App\Http\Requests\PlatformBilling\StartCmiCheckoutRequest;
use App\Models\PlatformBilling\SaasPaymentAttempt;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\PlatformBilling\Cmi\CmiHostedGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantSaasCheckoutController extends Controller
{
    public function store(
        StartCmiCheckoutRequest $request,
        SaasSubscription $subscription,
        StartCmiCheckout $start,
        TenantContext $context,
    ): RedirectResponse {
        abort_unless($subscription->tenant_id === $context->tenantId(), 404);
        $attempt = $start->handle(
            $subscription,
            $request->user(),
            $request->validated('idempotency_key'),
        );

        return redirect()->route('tenant-saas-checkout.show', $attempt);
    }

    public function show(
        Request $request,
        SaasPaymentAttempt $attempt,
        CmiHostedGateway $gateway,
        TenantContext $context,
    ): View|RedirectResponse {
        abort_unless($request->user()->isTenantOwner() && $attempt->tenant_id === $context->tenantId(), 404);
        if ($attempt->status !== SaasPaymentAttemptStatus::Pending || $attempt->expires_at->isPast()) {
            return redirect()->route('tenant-saas-account.show')
                ->with('error', 'Cette tentative de paiement n’est plus disponible.');
        }

        $attempt->load('subscription.plan');

        return view('tenant.cmi-checkout', [
            'attempt' => $attempt,
            'endpoint' => $gateway->endpoint(),
            'fields' => $gateway->checkoutFields($attempt),
        ]);
    }
}
