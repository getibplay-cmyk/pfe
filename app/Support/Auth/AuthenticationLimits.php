<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthenticationLimits
{
    public static function identity(Request $request): string
    {
        $value = $request->input('email');

        return hash_hmac('sha256', is_string($value) ? mb_strtolower(trim(mb_substr($value, 0, 255))) : 'invalid', (string) config('app.key'));
    }

    /** @return array<string, int> */
    public static function loginKeys(Request $request): array
    {
        return [
            'auth-login-global' => max(60, min(10000, (int) config('security.authentication.global_per_minute', 600))),
            'auth-login-account:'.self::identity($request) => 10,
            'auth-login-source:'.hash('sha256', (string) $request->ip()) => 30,
        ];
    }

    public static function reserveLogin(Request $request): void
    {
        foreach (self::loginKeys($request) as $key => $limit) {
            // Atomic increment also counts successful attempts; rotating accounts or sources cannot reset it.
            if (RateLimiter::hit($key, 60) > $limit) {
                throw ValidationException::withMessages(['email' => __('auth.throttle', [
                    'seconds' => max(1, RateLimiter::availableIn($key)), 'minutes' => 1,
                ])]);
            }
        }
    }
}
