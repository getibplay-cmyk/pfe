<?php

namespace App\Http\Controllers;

use App\Enums\IntelligenceCapability;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use App\Support\Intelligence\IntelligenceCapabilityCatalog;
use App\Support\Intelligence\TenantIntelligenceAccess;
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
    ): View {
        abort_unless($request->user()->isTenantOwner(), 403);

        $tenantId = $context->tenantId();
        $tenant = Tenant::query()->findOrFail($tenantId);
        $currentSubscription = SaasSubscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::CURRENT_STATUSES)
            ->latest('starts_at')
            ->first();
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
        $enabledCapabilities = collect(IntelligenceCapability::cases())
            ->filter(fn (IntelligenceCapability $capability): bool => $request->user()
                ->hasPermission($catalog->permission($capability))
                && $intelligenceAccess->status($capability, $tenantId)->usable())
            ->map(fn (IntelligenceCapability $capability): string => $catalog->definition($capability)['label'])
            ->values();

        return view('tenant.account-saas', compact(
            'tenant',
            'currentSubscription',
            'subscriptions',
            'payments',
            'enabledCapabilities',
        ));
    }
}
