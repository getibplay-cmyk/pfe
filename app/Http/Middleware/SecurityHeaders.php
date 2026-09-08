<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }

        foreach (config('security.headers') as $name => $value) {
            $response->headers->set($name, $value);
        }

        if ($request->is('commencer/*', 'reset-password*', 'forgot-password*', 'verify-email*', 'locataire', 'locataire/*', 'customers/*/portal-access')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        if ($request->user() !== null || $request->is('tenant/*', 'platform/*', 'billing/cmi/*',
            'commencer/*', 'login', 'forgot-password*', 'reset-password*', 'verify-email*', 'confirm-password', 'locataire', 'locataire/*')) {
            $explicitExpiry = $response->headers->hasCacheControlDirective('max-age');
            $response->headers->set('Cache-Control', $explicitExpiry ? 'no-store, private, max-age=0' : 'no-store, private');
        }

        if (app()->environment('production') && $request->isSecure() && config('security.hsts.enabled')) {
            $value = 'max-age='.(int) config('security.hsts.max_age');
            if (config('security.hsts.include_subdomains')) {
                $value .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
