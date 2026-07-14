<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->is_active, 403, 'Ce compte est inactif.');
        abort_if($user->is_platform_admin, 403, 'Utilisez les routes d’administration de plateforme.');
        abort_unless($user->tenant_id && $user->tenant?->status === TenantStatus::Active, 403, 'Aucun tenant actif associé.');

        $this->context->setFromUser($user);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
