<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\Auth\PendingEmailChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(ProfileUpdateRequest $request, PendingEmailChange $changes): RedirectResponse
    {
        $changed = $request->validated('email') !== $request->user()->email;
        $delivered = $changes->updateProfile($request, $request->safe()->only(['name', 'email']));
        if (! $delivered) {
            return to_route('profile.edit')->with('error', __('Profil enregistré. Un e-mail de confirmation n’a pas pu être envoyé ; votre adresse actuelle reste valable.'));
        }

        return to_route('profile.edit')->with('status', $changed
            ? __('Confirmez votre nouvelle adresse avec le lien reçu. Votre adresse actuelle reste valable.')
            : 'profile-updated');
    }
}
