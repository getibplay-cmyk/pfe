<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeRequiredPasswordRequest;
use App\Support\Auth\AccountSecurity;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ChangeRequiredPasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-required-password');
    }

    public function update(ChangeRequiredPasswordRequest $request, AccountSecurity $security): RedirectResponse
    {
        $security->changePassword($request, $request->validated('current_password'), $request->validated('password'), initial: true);

        return redirect()->route($request->user()->is_platform_admin ? 'platform.dashboard' : 'dashboard')
            ->with('status', __('Mot de passe personnel enregistré.'));
    }
}
