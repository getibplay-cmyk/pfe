<?php

namespace App\Http\Controllers;

use App\Enums\IntelligenceCapability;
use App\Models\PlatformBilling\SaasInvoice;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPaymentAttempt;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use App\Support\Intelligence\IntelligenceCapabilityCatalog;
use App\Support\Intelligence\TenantIntelligenceAccess;
use App\Support\PlatformBilling\Cmi\CmiConfiguration;
use App\Support\PlatformBilling\TenantPlanAccess;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TenantSaasAccountController extends Controller
{
    private const CURRENT_STATUSES = ['trialing', 'active', 'past_due', 'suspended'];

    public function __invoke(
        Request $request,
        TenantContext $context,
        IntelligenceCapabilityCatalog $catalog,
        TenantIntelligenceAccess $intelligenceAccess,
        CmiConfiguration $cmiConfiguration,
        TenantPlanAccess $planAccess,
    ): View {
        abort_unless($request->user()->isTenantOwner() && ($request->user()->role?->is_active ?? false), 403);

        $tenantId = $context->tenantId();
        $tenant = Tenant::query()->findOrFail($tenantId);
        $currentSubscription = SaasSubscription::query()
            ->with('plan')
            ->withExists(['invoices', 'invoices as open_invoices_exist' => fn ($query) => $query->where('status', 'open')])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::CURRENT_STATUSES)
            ->latest('starts_at')
            ->first();
        $pendingChange = SaasSubscription::query()->with('plan')->where('tenant_id', $tenantId)->where('status', 'pending_payment')->first();
        $currentCheckoutAvailable = $currentSubscription !== null && $pendingChange === null
            && DecimalMoney::toMinorUnits($currentSubscription->price_amount) > 0
            && $currentSubscription->currency === config('platform_billing.cmi.currency')
            && ($currentSubscription->status->value !== 'suspended' || $currentSubscription->billing_suspended)
            && (! $currentSubscription->invoices_exists || $currentSubscription->open_invoices_exist);
        $subscriptions = SaasSubscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->latest('starts_at')
            ->limit(25)
            ->get();
        $payments = SaasPayment::query()
            ->where('tenant_id', $tenantId)
            ->latest('occurred_at')
            ->limit(50)
            ->get();
        $paymentAttempts = SaasPaymentAttempt::query()
            ->where('tenant_id', $tenantId)
            ->latest()
            ->limit(20)
            ->get();
        $enabledCapabilities = collect(IntelligenceCapability::cases())
            ->filter(fn (IntelligenceCapability $capability): bool => $request->user()
                ->hasPermission($catalog->permission($capability))
                && $intelligenceAccess->status($capability, $tenantId)->usable())
            ->map(fn (IntelligenceCapability $capability): string => $catalog->definition($capability)['label'])
            ->values();
        $planState = $planAccess->state($tenantId);
        $activationSteps = [
            ['label' => __('Compte vérifié'), 'complete' => $request->user()->hasVerifiedEmail()],
            ['label' => __('Formule attribuée'), 'complete' => $currentSubscription !== null],
            ['label' => __('Service accessible'), 'complete' => $planState['available']],
        ];

        return view('tenant.account-saas', [
            ...compact(
                'tenant',
                'currentSubscription',
                'subscriptions',
                'payments',
                'paymentAttempts',
                'enabledCapabilities',
                'pendingChange',
                'currentCheckoutAvailable',
            ),
            'cmiReadiness' => $cmiConfiguration->readiness(),
            'planState' => $planState,
            'activationSteps' => $activationSteps,
            'quotas' => $planAccess->quotaSummary($tenantId),
            'availablePlans' => SaasPlan::query()->where('is_active', true)
                ->when($currentSubscription, fn ($query) => $query->where('currency', $currentSubscription->currency)
                    ->where('id', '<>', $currentSubscription->saas_plan_id))->orderBy('price_amount')->get(),
            'invoices' => SaasInvoice::query()->where('tenant_id', $tenantId)->latest()->paginate(15, ['*'], 'invoices_page'),
        ]);
    }
}
