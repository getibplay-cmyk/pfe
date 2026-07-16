<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeRequiredPasswordRequest;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ChangeRequiredPasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-required-password');
    }

    public function update(ChangeRequiredPasswordRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'must_change_password' => false,
        ])->save();
        $audit->record('user.initial_password_changed', $user, ['must_change_password' => true], ['must_change_password' => false]);

        return redirect()->route('dashboard')->with('status', 'Mot de passe personnel enregistré.');
    }
}
