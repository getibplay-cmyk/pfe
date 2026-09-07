<?php

namespace App\Http\Middleware;

use App\Models\CustomerPortalAccess;
use App\Support\Tenancy\CustomerPortalContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCustomerPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session()->get('customer_portal');
        abort_unless(is_array($session) && is_string($session['id'] ?? null) && is_string($session['proof'] ?? null), 403, 'Ouvrez le lien personnel fourni par votre agence.');
        $access = CustomerPortalAccess::withoutGlobalScopes()->findOrFail($session['id']);
        abort_unless($access->consumed_at && $access->session_expires_at?->gt(now())
            && is_string($access->session_hash) && hash_equals($access->session_hash, hash('sha256', $session['proof'])), 403, 'Votre session a expiré. Demandez un nouvel accès à votre agence.');
        $customer = app(CustomerPortalContext::class)->customer($access);
        $request->attributes->set('portal_customer', $customer);
        $request->attributes->set('portal_access', $access);

        return app(TenantContext::class)->run((int) $access->tenant_id, fn () => $next($request), (int) $access->agency_id);
    }
}
