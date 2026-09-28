<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

class ResetUserPasswordAdministratively
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(User $user, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($user, $password): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => true,
                'remember_token' => null,
                'security_version' => $locked->security_version + 1,
                'pending_email' => null,
                'pending_email_token_hash' => null,
                'pending_email_expires_at' => null,
            ])->save();

            Password::deleteToken($locked);
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $locked->id)->delete();
            $this->audit->record(
                'user.password_reset.administrative',
                $locked,
                [],
                ['must_change_password' => true],
            );

            return $locked;
        });
    }
}
