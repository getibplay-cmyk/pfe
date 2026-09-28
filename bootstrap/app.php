<?php

use App\Http\Middleware\BoundJsonRequests;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureJ11ContractDemoEnabled;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureSessionFreshness;
use App\Http\Middleware\EnsureTenantIntelligenceAccess;
use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\ResolveCustomerPortal;
use App\Http\Middleware\ResolveLocale;
use App\Http\Middleware\ResolvePublicBooking;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'billing/cmi/callback',
            'billing/cmi/return/*',
        ]);

        $middleware->trustHosts(at: fn (): array => [
            '^'.preg_quote((string) (parse_url(config('app.url'), PHP_URL_HOST) ?: 'invalid.invalid'), '/').'$',
        ], subdomains: false);

        $middleware->replace(TrustProxies::class, TrustedProxies::class);

        $middleware->prepend([
            SecurityHeaders::class,
            RequestCorrelation::class,
            BoundJsonRequests::class,
        ]);

        $middleware->alias([
            'password.confirm' => RequireRecentPassword::class,
            'active.account' => EnsureActiveAccount::class,
            'tenant' => ResolveTenantContext::class,
            'customer.portal' => ResolveCustomerPortal::class,
            'public.booking' => ResolvePublicBooking::class,
            'platform' => EnsurePlatformAdmin::class,
            'password.changed' => EnsurePasswordChanged::class,
            'intelligence.contract-demo' => EnsureJ11ContractDemoEnabled::class,
            'tenant.intelligence' => EnsureTenantIntelligenceAccess::class,
        ]);

        $middleware->web(append: [ResolveLocale::class, EnsureSessionFreshness::class, EnsureMfaVerified::class]);
        $middleware->prependToPriorityList(EnsureMfaVerified::class, EnsureSessionFreshness::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureMfaVerified::class);
        $middleware->prependToPriorityList(EnsureMfaVerified::class, ResolveLocale::class);

        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['code', 'session_key']);
        $exceptions->report(function (Throwable $exception) {
            if (! app()->environment('production')) {
                return null;
            }
            Log::error('Application failure.', [
                'exception_class' => $exception::class,
                'correlation_id' => request()->attributes->get('correlation_id'),
                'route' => request()->route()?->getName(),
            ]);

            return false;
        });
    })->create();
