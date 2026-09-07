<?php

namespace App\Http\Controllers;

use App\Actions\Platform\CreateTenantOnboardingInvitation;
use App\Actions\Platform\RevokeTenantOnboardingInvitation;
use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Http\Requests\Platform\StoreTenantOnboardingInvitationRequest;
use App\Models\Platform\TenantOnboardingInvitation;
use App\Models\PlatformBilling\SaasPlan;
use App\Notifications\TenantOnboardingInvitationNotification;
use App\Support\PlatformBilling\SaasPlanEntitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PlatformOnboardingInvitationController extends Controller
{
    public function index(Request $request, SaasPlanEntitlements $entitlements): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TenantOnboardingInvitationStatus::class)],
        ]);
        $invitations = TenantOnboardingInvitation::query()
            ->with(['plan', 'creator', 'acceptedTenant'])
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query
                ->where('email', 'ilike', '%'.$search.'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('platform.onboarding-invitations.index', [
            'invitations' => $invitations,
            'plans' => SaasPlan::query()
                ->where('is_active', true)
                ->orderBy('price_amount')
                ->get()
                ->filter(function (SaasPlan $plan) use ($entitlements): bool {
                    $rights = $entitlements->normalize($plan->entitlements);

                    return $rights['max_agencies'] !== 0 && $rights['max_users'] !== 0;
                })
                ->values(),
            'statuses' => TenantOnboardingInvitationStatus::cases(),
        ]);
    }

    public function store(
        StoreTenantOnboardingInvitationRequest $request,
        CreateTenantOnboardingInvitation $create,
        RevokeTenantOnboardingInvitation $revoke,
    ): RedirectResponse {
        if ($this->mailerWritesToLogs((string) config('mail.default'))) {
            return back()->withInput()->withErrors([
                'email' => 'Configurez un transport e-mail réel avant l’envoi : le transport « log » exposerait le lien personnel dans les journaux.',
            ]);
        }

        $result = $create->handle($request->validated(), $request->user()->getKey());

        try {
            Notification::route('mail', $result['invitation']->email)
                ->notify(new TenantOnboardingInvitationNotification($result['invitation'], $result['token']));
        } catch (Throwable $exception) {
            $revoke->handle($result['invitation'], $request->user()->getKey());
            Log::warning('Tenant onboarding invitation delivery failed.', [
                'invitation_id' => $result['invitation']->getKey(),
                'exception_class' => $exception::class,
            ]);

            return back()->withInput()->withErrors([
                'email' => 'L’invitation n’a pas pu être envoyée. Elle a été révoquée ; vérifiez la configuration e-mail puis recommencez.',
            ]);
        }

        return redirect()->route('platform.onboarding-invitations.index')
            ->with('status', 'Invitation envoyée. Le lien personnel ne sera jamais affiché ni conservé en clair.');
    }

    public function revoke(
        Request $request,
        TenantOnboardingInvitation $invitation,
        RevokeTenantOnboardingInvitation $revoke,
    ): RedirectResponse {
        $revoke->handle($invitation, $request->user()->getKey());

        return back()->with('status', 'Invitation révoquée. Son lien n’est plus utilisable.');
    }

    /** @param array<string, bool> $visited */
    private function mailerWritesToLogs(string $mailer, array &$visited = []): bool
    {
        if ($mailer === '' || isset($visited[$mailer])) {
            return false;
        }
        $visited[$mailer] = true;
        $configuration = config("mail.mailers.{$mailer}");
        if (! is_array($configuration)) {
            return $mailer === 'log';
        }
        if (($configuration['transport'] ?? null) === 'log') {
            return true;
        }

        foreach ((array) ($configuration['mailers'] ?? []) as $nested) {
            if (is_string($nested) && $this->mailerWritesToLogs($nested, $visited)) {
                return true;
            }
        }

        return false;
    }
}
