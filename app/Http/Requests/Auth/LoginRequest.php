<?php

namespace App\Http\Requests\Auth;

use App\Enums\TenantStatus;
use App\Models\User;
use App\Support\Auth\PasswordHashInspector;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            ...$this->only('email', 'password'),
            'is_active' => true,
        ];
        $candidate = User::query()
            ->where('email', $this->string('email')->toString())
            ->where('is_active', true)
            ->first(['id', 'password']);
        $inspector = app(PasswordHashInspector::class);

        if ($candidate && ! $inspector->isCompatible($candidate->getAuthPassword())) {
            $this->recordIncompatibleHash($candidate);
            $authenticated = false;
        } else {
            try {
                $authenticated = Auth::attempt($credentials, $this->boolean('remember'));
            } catch (RuntimeException $exception) {
                $currentHash = $candidate?->fresh(['id', 'password'])?->getAuthPassword();
                if ($candidate === null || $inspector->isCompatible($currentHash)) {
                    throw $exception;
                }

                $this->recordIncompatibleHash($candidate);
                $authenticated = false;
            }
        }

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = Auth::user();
        $tenantIsActive = $user->is_platform_admin || DB::table('tenants')
            ->where('id', $user->tenant_id)
            ->where('status', TenantStatus::Active->value)
            ->exists();
        $agencyIsActive = $user->agency_id === null || DB::table('agencies')
            ->where('id', $user->agency_id)
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->exists();
        if (! $tenantIsActive || ! $agencyIsActive) {
            Auth::guard('web')->logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    private function recordIncompatibleHash(User $user): void
    {
        Log::warning('Authentification refusée : empreinte de mot de passe incompatible.', [
            'event' => 'auth.password_hash_incompatible',
            'user_id' => $user->id,
        ]);
    }
}
