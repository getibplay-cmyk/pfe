<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AccountSecurity;
use App\Support\Auth\Totp;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AccountSecurityController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = config('session.driver') === 'database'
            ? DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)
                ->orderByDesc('last_activity')->limit(50)->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn ($session) => [
                    'current' => hash_equals($request->session()->getId(), $session->id),
                    'key' => Crypt::encryptString($session->id), 'ip' => $session->ip_address,
                    'device' => Str::limit((string) $session->user_agent, 180), 'at' => $session->last_activity,
                ]) : collect();

        return view('profile.security', ['user' => $request->user(), 'sessions' => $sessions]);
    }

    public function prepare(Request $request, Totp $totp): RedirectResponse
    {
        DB::transaction(function () use ($request, $totp) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_if($user->mfa_confirmed_at !== null, 409);
            $user->forceFill(['mfa_pending_secret' => $totp->secret(), 'mfa_pending_at' => now()])->save();
        });

        return to_route('security.index');
    }

    public function enroll(Request $request, AccountSecurity $security): View
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $result = $security->enroll($request->user(), $data['code']);
        $codes = $result['codes'];
        $security->trustSession($request, $result['version']);
        $security->revokeOtherSessions($request);

        return view('profile.recovery-codes', compact('codes'));
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        return $request->user()->mfa_confirmed_at ? view('auth.mfa-challenge') : to_route('security.index');
    }

    public function verify(Request $request, AccountSecurity $security): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $version = $security->verify($request->user(), $data['code']);
        $security->trustSession($request, $version);

        return redirect()->intended(route($request->user()->is_platform_admin ? 'platform.dashboard' : 'dashboard'));
    }

    public function disable(Request $request, AccountSecurity $security, AuditRecorder $audit): RedirectResponse
    {
        abort_if(config('security.mfa_require_admins', false) && ($request->user()->is_platform_admin || $request->user()->isTenantOwner()), 403);
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $version = DB::transaction(function () use ($request, $data, $security, $audit) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $security->verify($user, $data['code']);
            $user->refresh()->forceFill([
                'mfa_secret' => null, 'mfa_confirmed_at' => null, 'mfa_recovery_hashes' => null,
                'mfa_last_counter' => null, 'security_version' => $user->security_version + 1, 'remember_token' => Str::random(60),
            ])->save();
            $audit->record('account.mfa_disabled', $user);

            return $user->security_version;
        });
        $security->trustSession($request, $version);
        $security->revokeOtherSessions($request);

        return to_route('security.index')->with('status', __('Double authentification désactivée. Les autres sessions ont été fermées.'));
    }

    public function revoke(Request $request, AuditRecorder $audit): RedirectResponse
    {
        abort_unless(config('session.driver') === 'database', 409);
        $data = $request->validate(['session_key' => ['required', 'string', 'max:1000']]);
        try {
            $id = Crypt::decryptString($data['session_key']);
        } catch (DecryptException) {
            abort(404);
        }
        abort_if(hash_equals($request->session()->getId(), $id), 422);
        $deleted = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)->where('id', $id)->delete();
        abort_unless($deleted, 404);
        $request->user()->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        $audit->record('account.session_revoked', $request->user());

        return to_route('security.index')->with('status', __('Session révoquée.'));
    }
}
