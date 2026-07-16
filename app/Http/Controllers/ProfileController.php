<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if ($user->role?->slug === 'tenant-owner') {
            $otherActiveOwners = User::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('role_id', $user->role_id)
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->exists();
            if (! $otherActiveOwners) {
                $passwordField = 'password';

                return back()->withErrors([$passwordField => 'Le dernier Tenant Owner actif ne peut pas désactiver son compte.'], 'userDeletion');
            }
        }

        $user->forceFill(['is_active' => false, 'remember_token' => null])->save();
        $audit->record('user.self_deactivated', $user, ['is_active' => true], ['is_active' => false]);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
