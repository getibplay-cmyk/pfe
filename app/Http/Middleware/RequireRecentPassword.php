<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Carbon;

final class RequireRecentPassword extends RequirePassword
{
    protected function shouldConfirmPassword($request, $passwordTimeoutSeconds = null): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $now = Carbon::now()->timestamp;
        $timeout = min(900, max(1, (int) ($passwordTimeoutSeconds ?? $this->passwordTimeout)));

        // Missing, malformed or future proofs must never extend an elevation.
        return ! is_int($confirmedAt) || $confirmedAt > $now
            || $now >= $confirmedAt + $timeout;
    }
}
