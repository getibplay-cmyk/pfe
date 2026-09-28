<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Notifications\Auth\EmailChangeNotification;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PendingEmailChange
{
    public function updateProfile(Request $request, array $data): bool
    {
        $delivery = DB::transaction(function () use ($request, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            app(AccountSecurity::class)->assertCurrentMfaProof($request, $user);
            $user->name = $data['name'];
            $changed = $data['email'] !== $user->email;
            $token = null;
            if ($changed) {
                if (! Hash::check((string) $request->input('current_password'), $user->password)) {
                    throw ValidationException::withMessages(['current_password' => __('auth.password')]);
                }
                $token = bin2hex(random_bytes(32));
                $user->forceFill(['pending_email' => $data['email'], 'pending_email_token_hash' => hash('sha256', $token), 'pending_email_expires_at' => now()->addMinutes(30)]);
            }
            $user->save();
            app(AuditRecorder::class)->record('profile.updated', $user, [], ['name_updated' => true, 'email_change_requested' => $changed]);

            return [$user, $token];
        });
        [$user, $token] = $delivery;
        if ($token === null) {
            return true;
        }
        $url = URL::temporarySignedRoute('profile.email-change.show', $user->pending_email_expires_at, ['token' => $token]);
        $delivered = true;
        foreach ([[$user->pending_email, $url], [$user->email, null]] as [$address, $link]) {
            try {
                Notification::route('mail', $address)->notify((new EmailChangeNotification($link))->locale($user->preferredLocale()));
            } catch (Throwable) {
                $delivered = false;
                Log::warning('Email change notification delivery failed.', ['event' => 'account.email_change.delivery_failed', 'user_id' => $user->id]);
            }
        }

        return $delivered;
    }

    public function validateToken(User $user, string $token): void
    {
        abort_unless(strlen($token) === 64 && $user->pending_email !== null
            && $user->pending_email_expires_at?->isFuture()
            && hash_equals((string) $user->pending_email_token_hash, hash('sha256', $token)), 404);
    }

    public function confirm(Request $request, string $token): void
    {
        $version = DB::transaction(function () use ($request, $token) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $this->validateToken($user, $token);
            app(AccountSecurity::class)->assertCurrentMfaProof($request, $user);
            if (User::query()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('Cette adresse e-mail est déjà utilisée.')]);
            }
            Password::deleteToken($user);
            $user->forceFill([
                'email' => $user->pending_email, 'email_verified_at' => now(),
                'pending_email' => null, 'pending_email_token_hash' => null, 'pending_email_expires_at' => null,
                'remember_token' => Str::random(60), 'security_version' => $user->security_version + 1,
            ])->save();
            app(AccountSecurity::class)->revokeOtherSessions($request);
            app(AuditRecorder::class)->record('account.email_change_confirmed', $user, [], ['sessions_revoked' => true]);

            return $user->security_version;
        });
        $request->user()->refresh();
        app(AccountSecurity::class)->trustSession($request, $version);
    }

    public function cancel(Request $request): void
    {
        DB::transaction(function () use ($request) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            app(AccountSecurity::class)->assertCurrentMfaProof($request, $user);
            $user->forceFill(['pending_email' => null, 'pending_email_token_hash' => null, 'pending_email_expires_at' => null])->save();
            app(AuditRecorder::class)->record('account.email_change_cancelled', $user);
        });
    }
}
