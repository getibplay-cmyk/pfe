<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountSecurity
{
    public function __construct(private Totp $totp, private AuditRecorder $audit) {}

    public function verify(User $user, string $code): int
    {
        return DB::transaction(function () use ($user, $code) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $locked->is_active || ! $locked->mfa_confirmed_at || ! $locked->mfa_secret) {
                $this->invalidCode();
            }
            $counter = $this->totp->match($locked->mfa_secret, $code, $locked->mfa_last_counter);
            $recovery = false;
            if ($counter !== null) {
                $locked->forceFill(['mfa_last_counter' => $counter])->save();
            } else {
                $hashes = $locked->mfa_recovery_hashes ?? [];
                $index = array_search(hash('sha256', strtolower($code)), $hashes, true);
                if ($index === false) {
                    $this->invalidCode();
                }
                unset($hashes[$index]);
                $locked->forceFill(['mfa_recovery_hashes' => array_values($hashes)])->save();
                $recovery = true;
            }
            $this->audit->record('account.mfa_verified', $locked, [], ['recovery_used' => $recovery]);

            return $locked->security_version;
        });
    }

    /** @return array{codes: list<string>, version: int} */
    public function enroll(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($locked->mfa_confirmed_at !== null, 409);
            if (! $locked->mfa_pending_secret || ! $locked->mfa_pending_at || $locked->mfa_pending_at->lt(now()->subMinutes(10))) {
                throw ValidationException::withMessages(['code' => __('La préparation a expiré. Recommencez l’activation.')]);
            }
            $counter = $this->totp->match($locked->mfa_pending_secret, $code);
            if ($counter === null) {
                $this->invalidCode();
            }
            $codes = $this->recoveryCodes();
            $locked->forceFill([
                'mfa_secret' => $locked->mfa_pending_secret, 'mfa_pending_secret' => null, 'mfa_pending_at' => null,
                'mfa_confirmed_at' => now(), 'mfa_last_counter' => $counter,
                'mfa_recovery_hashes' => array_map(fn ($value) => hash('sha256', $value), $codes),
                'security_version' => $locked->security_version + 1, 'remember_token' => Str::random(60),
            ])->save();
            $this->audit->record('account.mfa_enabled', $locked);

            return ['codes' => $codes, 'version' => $locked->security_version];
        });
    }

    public function trustSession(Request $request, int $version): void
    {
        $request->session()->regenerate();
        $request->session()->put('mfa_verified', [
            'user_id' => $request->user()->id, 'version' => $version,
        ]);
    }

    public function revokeOtherSessions(Request $request): void
    {
        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
    }

    /** @return list<string> */
    private function recoveryCodes(): array
    {
        return array_map(fn () => implode('-', str_split(bin2hex(random_bytes(8)), 4)), range(1, 10));
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages(['code' => __('Code invalide, expiré ou déjà utilisé.')]);
    }
}
