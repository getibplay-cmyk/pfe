<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSessionFreshness
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $session = $request->session();
            $proof = $session->get('account_session');
            $now = now()->timestamp;
            $minutes = max(15, min(480, (int) config('security.session_absolute_minutes', 480)));
            if ($proof !== null && (! is_array($proof) || ($proof['user_id'] ?? null) !== $user->id
                || ($proof['version'] ?? null) !== $user->security_version
                || ! is_int($proof['started_at'] ?? null)
                || $proof['started_at'] > $now
                || $now >= $proof['started_at'] + $minutes * 60)) {
                Auth::guard('web')->logout();
                $session->invalidate();
                $session->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => __('Votre session a expiré. Reconnectez-vous.'), 'redirect' => route('login')], 401)
                    : redirect()->route('login')->with('status', __('Votre session a expiré. Reconnectez-vous.'));
            }
            if ($proof === null) {
                $session->put('account_session', ['user_id' => $user->id, 'version' => $user->security_version, 'started_at' => $now]);
            }
        }

        return $next($request);
    }
}
