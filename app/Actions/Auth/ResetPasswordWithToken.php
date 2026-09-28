<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

final class ResetPasswordWithToken
{
    public function handle(#[SensitiveParameter] array $credentials): string
    {
        // Lock before token validation: simultaneous redemptions cannot both succeed.
        return DB::transaction(function () use ($credentials) {
            $user = User::query()->where('email', $credentials['email'])->lockForUpdate()->first();
            if (! $user || ! $user->is_active || (! $user->is_platform_admin && ! DB::table('tenants')
                ->where('id', $user->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists())
                || ($user->agency_id !== null && ! DB::table('agencies')->where('id', $user->agency_id)
                    ->where('tenant_id', $user->tenant_id)->where('is_active', true)->whereNull('deleted_at')->exists())) {
                return Password::INVALID_TOKEN;
            }

            return Password::reset($credentials, function (User $locked, string $password) {
                $locked->forceFill([
                    'password' => Hash::make($password), 'remember_token' => Str::random(60),
                    'must_change_password' => false, 'security_version' => $locked->security_version + 1,
                    'pending_email' => null, 'pending_email_token_hash' => null, 'pending_email_expires_at' => null,
                ])->save();
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $locked->id)->delete();
                app(AuditRecorder::class)->record('user.password_reset.email', $locked, [], ['sessions_revoked' => true]);
                DB::afterCommit(fn () => event(new PasswordReset($locked)));
            });
        });
    }
}
