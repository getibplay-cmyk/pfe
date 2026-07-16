<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->is_active, 403, 'Ce compte est inactif.');

        if ($user->is_platform_admin) {
            return $next($request);
        }

        $tenantIsActive = $user->tenant_id !== null
            && DB::table('tenants')
                ->where('id', $user->tenant_id)
                ->where('status', TenantStatus::Active->value)
                ->whereNull('deleted_at')
                ->exists();
        abort_unless($tenantIsActive, 403, 'Le tenant associé à ce compte est indisponible.');

        $agencyIsActive = $user->agency_id === null
            || DB::table('agencies')
                ->where('id', $user->agency_id)
                ->where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->exists();
        abort_unless($agencyIsActive, 403, 'L’agence associée à ce compte est inactive.');

        return $next($request);
    }
}
