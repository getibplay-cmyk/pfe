<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            ])->save();

            DB::table('sessions')->where('user_id', $locked->id)->delete();
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
