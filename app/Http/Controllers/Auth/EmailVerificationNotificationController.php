<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\VerificationNotificationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request, VerificationNotificationSender $sender): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            $destination = $request->user()->is_platform_admin
                ? route('platform.dashboard', absolute: false)
                : route('dashboard', absolute: false);

            return redirect()->intended($destination);
        }

        if (! $sender->send($request->user())) {
            return back()->with('error', 'Le lien n’a pas pu être envoyé. Réessayez plus tard ou contactez l’administration.');
        }

        return back()->with('status', 'verification-link-sent');
    }
}
