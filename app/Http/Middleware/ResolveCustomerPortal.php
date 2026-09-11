<?php

namespace App\Http\Middleware;

use App\Models\CustomerPortalAccess;
use App\Support\Tenancy\CustomerPortalContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ResolveCustomerPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session()->get('customer_portal');
        try {
            abort_unless(is_array($session) && is_string($session['id'] ?? null) && is_string($session['proof'] ?? null), 403);
            $access = CustomerPortalAccess::withoutGlobalScopes()->findOrFail($session['id']);
            abort_unless($access->consumed_at && $access->session_expires_at?->gt(now())
                && is_string($access->session_hash) && hash_equals($access->session_hash, hash('sha256', $session['proof'])), 403);
            $customer = app(CustomerPortalContext::class)->customer($access);
        } catch (HttpExceptionInterface|ModelNotFoundException) {
            $request->session()->forget('customer_portal');
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Cet accès est indisponible. Demandez un nouveau lien à votre agence.'], 403);
            }

            return response()->view('portal.expired', [], 403);
        }
        $request->attributes->set('portal_customer', $customer);
        $request->attributes->set('portal_access', $access);
        $request->attributes->set('portal_expires_at', $access->session_expires_at->min($access->expires_at));

        return app(TenantContext::class)->run((int) $access->tenant_id, fn () => $next($request), (int) $access->agency_id);
    }
}
