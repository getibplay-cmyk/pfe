<?php

namespace App\Http\Controllers;

use App\Support\Auth\PendingEmailChange;
use Illuminate\Http\Request;

final class EmailChangeController extends Controller
{
    public function show(Request $request, string $token, PendingEmailChange $changes)
    {
        $changes->validateToken($request->user(), $token);

        return view('profile.confirm-email-change', ['email' => $request->user()->pending_email, 'confirmationUrl' => $request->fullUrl()]);
    }

    public function confirm(Request $request, string $token, PendingEmailChange $changes)
    {
        $changes->confirm($request, $token);

        return to_route('profile.edit')->with('status', __('Votre nouvelle adresse e-mail est confirmée.'));
    }

    public function cancel(Request $request, PendingEmailChange $changes)
    {
        $changes->cancel($request);

        return to_route('profile.edit')->with('status', __('La modification de l’adresse e-mail a été annulée.'));
    }
}
