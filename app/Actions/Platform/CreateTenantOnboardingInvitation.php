<?php

namespace App\Actions\Platform;

use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\Platform\TenantOnboardingInvitation;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\SaasPlanEntitlements;
use App\Support\Platform\PlatformAdminGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateTenantOnboardingInvitation
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
        private readonly SaasPlanEntitlements $entitlements,
    ) {}

    /** @return array{invitation: TenantOnboardingInvitation, token: string} */
    public function handle(array $data, int $actorId): array
    {
        $this->platformAdmin->actor($actorId);
        $this->rejectUnexpected($data, ['email', 'saas_plan_id', 'trial_days', 'expires_in_hours']);
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'L’adresse e-mail est invalide.']);
        }
        $trialDays = filter_var($data['trial_days'] ?? config('platform_billing.onboarding.default_trial_days'), FILTER_VALIDATE_INT);
        $expiresInHours = filter_var($data['expires_in_hours'] ?? config('platform_billing.onboarding.invitation_ttl_hours'), FILTER_VALIDATE_INT);
        if ($trialDays === false || $trialDays < 1 || $trialDays > 90) {
            throw ValidationException::withMessages(['trial_days' => 'La durée d’essai doit être comprise entre 1 et 90 jours.']);
        }
        if ($expiresInHours === false || $expiresInHours < 1 || $expiresInHours > 168) {
            throw ValidationException::withMessages(['expires_in_hours' => 'La validité du lien doit être comprise entre 1 et 168 heures.']);
        }

        try {
            return DB::transaction(function () use ($data, $actorId, $email, $expiresInHours, $trialDays): array {
                $plan = SaasPlan::query()
                    ->whereKey((int) ($data['saas_plan_id'] ?? 0))
                    ->lockForUpdate()
                    ->first();
                if ($plan === null || ! $plan->is_active) {
                    throw ValidationException::withMessages(['saas_plan_id' => 'Sélectionnez un plan SaaS actif.']);
                }

                $entitlements = $this->entitlements->normalize($plan->entitlements);
                if ($entitlements['max_agencies'] === 0 || $entitlements['max_users'] === 0) {
                    throw ValidationException::withMessages([
                        'saas_plan_id' => 'Ce plan doit autoriser au moins une agence et un utilisateur pour l’accueil initial.',
                    ]);
                }

                TenantOnboardingInvitation::query()
                    ->whereRaw('lower(email) = ?', [$email])
                    ->where('status', TenantOnboardingInvitationStatus::Pending->value)
                    ->where('expires_at', '<=', now())
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (TenantOnboardingInvitation $invitation) => $invitation->forceFill([
                        'status' => TenantOnboardingInvitationStatus::Expired,
                    ])->save());

                if (DB::table('users')->whereRaw('lower(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'Un compte utilise déjà cette adresse e-mail.']);
                }
                if (TenantOnboardingInvitation::query()
                    ->whereRaw('lower(email) = ?', [$email])
                    ->where('status', TenantOnboardingInvitationStatus::Pending->value)
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages(['email' => 'Une invitation active existe déjà pour cette adresse.']);
                }

                $token = Str::random(80);
                $invitation = new TenantOnboardingInvitation;
                $invitation->forceFill([
                    'id' => (string) Str::uuid(),
                    'email' => $email,
                    'secret_hash' => hash('sha256', $token),
                    'saas_plan_id' => $plan->getKey(),
                    'status' => TenantOnboardingInvitationStatus::Pending,
                    'trial_days' => $trialDays,
                    'expires_at' => now()->addHours($expiresInHours),
                    'created_by' => $actorId,
                ])->save();

                $this->audit->record('platform.onboarding_invitation.created', $plan, [], [
                    'invitation_id' => $invitation->getKey(),
                    'email' => $email,
                    'trial_days' => $invitation->trial_days,
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                ]);

                return compact('invitation', 'token');
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => 'Une invitation active existe déjà pour cette adresse.']);
            }

            throw $exception;
        }
    }

    private function rejectUnexpected(array $data, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($data), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => 'Ce champ n’est pas autorisé.']);
        }
    }
}
