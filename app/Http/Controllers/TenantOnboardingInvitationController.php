<?php

namespace App\Http\Controllers;

use App\Actions\Platform\AcceptTenantOnboardingInvitation;
use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Http\Requests\AcceptTenantOnboardingInvitationRequest;
use App\Models\Platform\TenantOnboardingInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class TenantOnboardingInvitationController extends Controller
{
    public function show(Request $request, TenantOnboardingInvitation $invitation): View
    {
        $token = (string) $request->query('token');
        $invitation->load(['plan', 'creator']);
        $this->ensureUsable($invitation, $token);

        return view('onboarding-invitations.accept', [
            'invitation' => $invitation,
            'acceptUrl' => URL::temporarySignedRoute(
                'onboarding-invitations.accept',
                $invitation->expires_at,
                ['invitation' => $invitation->getKey(), 'token' => $token],
            ),
        ]);
    }

    public function accept(
        AcceptTenantOnboardingInvitationRequest $request,
        TenantOnboardingInvitation $invitation,
        AcceptTenantOnboardingInvitation $accept,
    ): RedirectResponse {
        $result = $accept->handle($invitation, (string) $request->query('token'), $request->validated());
        Auth::login($result['owner']);
        $request->session()->regenerate();

        return redirect()->route('onboarding.index')
            ->with('status', __('Votre espace est prêt. Suivez ces étapes pour démarrer votre activité.'));
    }

    private function ensureUsable(TenantOnboardingInvitation $invitation, string $token): void
    {
        abort_unless(
            strlen($token) === 80 && hash_equals($invitation->secret_hash, hash('sha256', $token)),
            404,
        );
        abort_if(
            $invitation->status !== TenantOnboardingInvitationStatus::Pending
                || $invitation->expires_at->isPast()
                || ! $invitation->plan?->is_active
                || ! $invitation->creator?->is_active
                || ! $invitation->creator?->is_platform_admin
                || $invitation->creator?->tenant_id !== null,
            410,
            __('Cette invitation a expiré ou a déjà été utilisée.'),
        );
    }
}
