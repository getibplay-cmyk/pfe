<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|View
    {
        return $request->user()->hasVerifiedEmail()
                    ? redirect()->intended($this->destination($request))
                    : view('auth.verify-email');
    }

    private function destination(Request $request): string
    {
        return $request->user()->is_platform_admin
            ? route('platform.dashboard', absolute: false)
            : route('dashboard', absolute: false);
    }
}
