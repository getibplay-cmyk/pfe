<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\VerificationNotificationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(
        ProfileUpdateRequest $request,
        AuditRecorder $audit,
        VerificationNotificationSender $verificationSender,
    ): RedirectResponse
    {
        $old = $request->user()->only(['name', 'email']);
        $request->user()->fill($request->validated());

        $emailChanged = $request->user()->isDirty('email');
        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();
        $audit->record('profile.updated', $request->user(), $old, $request->user()->only(['name', 'email']));

        if ($emailChanged && ! $verificationSender->send($request->user())) {
            return Redirect::route('profile.edit')->with('error', 'Profil enregistré, mais le lien de vérification n’a pas pu être envoyé.');
        }

        return Redirect::route('profile.edit')->with(
            'status',
            $emailChanged ? 'Profil enregistré. Un lien de vérification a été envoyé.' : 'profile-updated',
        );
    }
}
