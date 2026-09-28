<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityEventLogger;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(24));
        Vite::useCspNonce($nonce);
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
        if ($request->attributes->has('correlation_id')) {
            $response->headers->set('X-Correlation-ID', $request->attributes->get('correlation_id'));
        }
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html') && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
            $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'");
        }
        if (in_array($response->getStatusCode(), [401, 403, 429], true)) {
            SecurityEventLogger::record('http.access_rejected', (string) $response->getStatusCode());
        }

        if ($request->is('commencer/*', 'reset-password*', 'forgot-password*', 'verify-email*', 'locataire', 'locataire/*', 'customers/*/portal-access', 'profile/security*', 'profile/email-change*', 'security/*')) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        if ($request->user() !== null || $request->is('tenant/*', 'platform/*', 'billing/cmi/*',
            'commencer/*', 'login', 'forgot-password*', 'reset-password*', 'verify-email*', 'confirm-password', 'locataire', 'locataire/*', 'profile', 'profile/*', 'security/*')) {
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
