<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->is_active, 403, 'Ce compte est inactif.');
        abort_if($user->is_platform_admin, 403, 'Utilisez les routes d’administration de plateforme.');
        $tenantIsActive = $user->tenant_id && DB::table('tenants')->where('id', $user->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists();
        abort_unless($tenantIsActive, 403, 'Aucun tenant actif associé.');
        $agencyIsActive = $user->agency_id === null || DB::table('agencies')->where('id', $user->agency_id)->where('tenant_id', $user->tenant_id)->where('is_active', true)->whereNull('deleted_at')->exists();
        abort_unless($agencyIsActive, 403, 'Cette agence est inactive.');

        $this->context->setFromUser($user);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
