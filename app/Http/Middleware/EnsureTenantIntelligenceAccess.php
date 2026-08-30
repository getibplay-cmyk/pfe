<?php

namespace App\Http\Middleware;

use App\Enums\IntelligenceCapability;
use App\Support\Intelligence\TenantIntelligenceAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantIntelligenceAccess
{
    public function __construct(private readonly TenantIntelligenceAccess $access) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $resolved = IntelligenceCapability::tryFrom($capability);
        abort_if($resolved === null, 403, 'Cette fonctionnalité n’est pas disponible.');

        $this->access->ensureAuthorized($resolved);

        return $next($request);
    }
}
