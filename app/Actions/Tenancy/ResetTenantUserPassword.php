<?php

namespace App\Actions\Tenancy;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\TemporaryPassword;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class ResetTenantUserPassword
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(User $subject): string
    {
        return DB::transaction(function () use ($subject): string {
            $locked = User::query()->lockForUpdate()->findOrFail($subject->id);
            abort_unless($locked->tenant_id === app(TenantContext::class)->tenantId(), 403);
            $temporaryPassword = TemporaryPassword::generate();
            $locked->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'remember_token' => null,
                'security_version' => $locked->security_version + 1,
                'pending_email' => null, 'pending_email_token_hash' => null, 'pending_email_expires_at' => null,
            ])->save();
            Password::deleteToken($locked);
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $locked->id)->delete();
            $this->audit->record('user.password_reset', $locked, [], ['must_change_password' => true]);

            return $temporaryPassword;
        });
    }
}
