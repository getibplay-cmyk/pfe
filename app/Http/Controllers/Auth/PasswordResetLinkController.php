<?php

namespace App\Http\Controllers\Auth;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->whereRaw('lower(email) = lower(?)', [$request->string('email')->toString()])->first();
        if ($user !== null && $this->mayResetPassword($user)) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Password reset notification could not be delivered.', [
                    'event' => 'auth.password_reset.delivery_failed',
                    'user_id' => $user->getKey(),
                    'tenant_id' => $user->tenant_id,
                ]);
            }
        }

        return back()->with('status', 'Si un compte actif correspond à cette adresse, un lien de réinitialisation vient d’être envoyé.');
    }

    private function mayResetPassword(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->is_platform_admin) {
            return true;
        }

        return $user->tenant_id !== null && DB::table('tenants')
            ->where('id', $user->tenant_id)
            ->where('status', TenantStatus::Active->value)
            ->whereNull('deleted_at')
            ->exists();
    }
}
