<?php

namespace App\Actions\Tenancy;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetTenantUserPassword
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(User $subject): string
    {
        return DB::transaction(function () use ($subject): string {
            $locked = User::query()->lockForUpdate()->findOrFail($subject->id);
            $temporaryPassword = TemporaryPassword::generate();
            $locked->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'remember_token' => null,
            ])->save();
            DB::table('sessions')->where('user_id', $locked->id)->delete();
            $this->audit->record('user.password_reset', $locked, [], ['must_change_password' => true]);

            return $temporaryPassword;
        });
    }
}
