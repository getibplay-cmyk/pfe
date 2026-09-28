<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class TrustedProxies
{
    public function handle(Request $request, Closure $next): mixed
    {
        $addresses = config('security.trusted_proxies', []);
        foreach ($addresses as $address) {
            $parts = explode('/', $address, 2);
            $bits = str_contains($parts[0], ':') ? 128 : 32;
            abort_unless(filter_var($parts[0], FILTER_VALIDATE_IP) !== false
                && (! isset($parts[1]) || (ctype_digit($parts[1]) && (int) $parts[1] >= 1 && (int) $parts[1] <= $bits)), 503);
        }
        // Host and prefix are never taken from forwarded headers. No wildcard or platform fallback.
        Request::setTrustedProxies($addresses, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

        return $next($request);
    }
}
