<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetPasswordWithToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, ResetPasswordWithToken $reset): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $candidate = User::query()->where('email', $request->input('email'))->first();
        if (! $candidate || ! $candidate->is_active || ! Password::tokenExists($candidate, $request->string('token')->toString())) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __(Password::INVALID_TOKEN)]);
        }
        // Avoid an external password lookup for an invalid recovery request.
        // The transactional action revalidates the token under lock before consuming it.
        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = $reset->handle($request->only('email', 'password', 'password_confirmation', 'token'));

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
