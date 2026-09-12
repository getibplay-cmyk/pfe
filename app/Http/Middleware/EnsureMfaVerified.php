<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $request->routeIs('security.challenge', 'security.verify', 'logout', 'locale.update')) {
            $proof = $request->session()->get('mfa_verified', []);
            if ($user->mfa_confirmed_at && ($proof['user_id'] ?? null) !== $user->id
                || $user->mfa_confirmed_at && ($proof['version'] ?? null) !== $user->security_version) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => __('Une double authentification est nécessaire.'), 'redirect' => route('security.challenge')], 403);
                }
                if ($request->isMethod('GET')) {
                    $request->session()->put('url.intended', $request->fullUrl());
                }

                return redirect()->route('security.challenge');
            }
            if (config('security.mfa_require_admins', false) && ! $user->mfa_confirmed_at
                && ($user->is_platform_admin || $user->isTenantOwner())
                && ! $request->routeIs('security.*', 'password.confirm', 'password.change-required', 'password.change-required.update')) {
                return $request->expectsJson()
                    ? response()->json(['message' => __('Activez la double authentification pour continuer.')], 403)
                    : redirect()->route('security.index')->with('status', __('Activez la double authentification pour continuer.'));
            }
        }

        return $next($request);
    }
}
