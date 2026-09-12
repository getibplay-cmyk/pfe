<?php

namespace App\Actions\Platform;

use App\Actions\PlatformBilling\AssignSaasSubscription;
use App\Enums\IntelligenceCapability;
use App\Enums\Platform\TenantOnboardingInvitationStatus;
use App\Enums\TenantStatus;
use App\Models\Agency;
use App\Models\Platform\TenantOnboardingInvitation;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntelligenceAccess;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\SaasPlanEntitlements;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AcceptTenantOnboardingInvitation
{
    public function __construct(
        private readonly AssignSaasSubscription $assignSubscription,
        private readonly AuditRecorder $audit,
        private readonly SaasPlanEntitlements $entitlements,
        private readonly TenantContext $context,
    ) {}

    /** @return array{tenant: Tenant, agency: Agency, owner: User} */
    public function handle(TenantOnboardingInvitation $invitation, string $token, array $data): array
    {
        $expired = false;

        try {
            $result = DB::transaction(function () use ($invitation, $token, $data, &$expired): ?array {
                $locked = TenantOnboardingInvitation::query()->whereKey($invitation)->lockForUpdate()->firstOrFail();
                if (! hash_equals($locked->secret_hash, hash('sha256', $token))) {
                    throw ValidationException::withMessages(['invitation' => __('Le lien d’invitation est invalide.')]);
                }
                if ($locked->status !== TenantOnboardingInvitationStatus::Pending) {
                    throw ValidationException::withMessages(['invitation' => __('Cette invitation a déjà été utilisée ou révoquée.')]);
                }
                $acceptedAt = CarbonImmutable::now();
                if ($locked->expires_at->lessThanOrEqualTo($acceptedAt)) {
                    $locked->forceFill(['status' => TenantOnboardingInvitationStatus::Expired])->save();
                    $expired = true;

                    return null;
                }

                $plan = SaasPlan::query()->whereKey($locked->saas_plan_id)->lockForUpdate()->firstOrFail();
                if (! $plan->is_active) {
                    throw ValidationException::withMessages(['invitation' => __('Le plan associé à cette invitation n’est plus disponible.')]);
                }
                $planEntitlements = $this->entitlements->normalize($plan->entitlements);
                if ($planEntitlements['max_agencies'] === 0 || $planEntitlements['max_users'] === 0) {
                    throw ValidationException::withMessages([
                        'invitation' => __('Le plan associé ne permet plus la création initiale de l’entreprise.'),
                    ]);
                }
                if (DB::table('users')->whereRaw('lower(email) = ?', [$locked->email])->exists()) {
                    throw ValidationException::withMessages(['invitation' => __('Un compte utilise déjà cette adresse e-mail.')]);
                }

                $creator = User::query()->whereKey($locked->created_by)->lockForUpdate()->first();
                if ($creator === null || ! $creator->is_active || ! $creator->is_platform_admin || $creator->tenant_id !== null) {
                    throw ValidationException::withMessages([
                        'invitation' => __('Cette invitation n’est plus autorisée par la plateforme.'),
                    ]);
                }
                $ownerRole = Role::query()
                    ->whereNull('tenant_id')
                    ->where('slug', 'tenant-owner')
                    ->where('is_active', true)
                    ->first();
                if ($ownerRole === null) {
                    throw ValidationException::withMessages([
                        'invitation' => __('La configuration du rôle administrateur est indisponible.'),
                    ]);
                }
                $tenant = Tenant::create([
                    'name' => trim((string) $data['company_name']),
                    'slug' => mb_strtolower(trim((string) $data['company_slug'])),
                    'legal_name' => $this->nullableText($data['legal_name'] ?? null),
                    'email' => $locked->email,
                    'phone' => $this->nullableText($data['company_phone'] ?? null),
                    'status' => TenantStatus::Active,
                    'settings' => [
                        'address' => $this->nullableText($data['company_address'] ?? null),
                        'currency' => 'MAD',
                        'timezone' => 'Africa/Casablanca',
                    ],
                ]);

                $includedCapabilities = $planEntitlements['intelligence_capabilities'];
                foreach (IntelligenceCapability::cases() as $capability) {
                    TenantIntelligenceAccess::forceCreate([
                        'tenant_id' => $tenant->getKey(),
                        'capability' => $capability,
                        'enabled' => in_array($capability->value, $includedCapabilities, true),
                        'updated_by' => $locked->created_by,
                        'changed_at' => $acceptedAt,
                    ]);
                }

                [$agency, $owner] = $this->context->run($tenant, function () use ($acceptedAt, $data, $locked, $ownerRole, $tenant): array {
                    $agency = Agency::create([
                        'code' => mb_strtoupper(trim((string) $data['agency_code'])),
                        'name' => trim((string) $data['agency_name']),
                        'email' => $this->nullableText($data['agency_email'] ?? null),
                        'phone' => $this->nullableText($data['agency_phone'] ?? null),
                        'address' => $this->nullableText($data['agency_address'] ?? null),
                        'is_active' => true,
                    ]);
                    $owner = User::forceCreate([
                        'tenant_id' => $tenant->getKey(),
                        'agency_id' => null,
                        'role_id' => $ownerRole->getKey(),
                        'name' => trim((string) $data['owner_name']),
                        'email' => $locked->email,
                        'email_verified_at' => $acceptedAt,
                        'password' => Hash::make((string) $data['password']),
                        'is_active' => true,
                        'must_change_password' => false,
                        'is_platform_admin' => false,
                    ]);

                    return [$agency, $owner];
                });

                $trialEndsAt = $acceptedAt->addDays($locked->trial_days);
                $this->assignSubscription->handle($tenant, $plan, [
                    'status' => 'trialing',
                    'starts_at' => $acceptedAt->toIso8601String(),
                    'ends_at' => $trialEndsAt->toIso8601String(),
                    'trial_ends_at' => $trialEndsAt->toIso8601String(),
                    'next_renewal_at' => $trialEndsAt->toIso8601String(),
                    'admin_note' => __('Essai créé automatiquement depuis une invitation d’accueil.'),
                ], (int) $locked->created_by);

                $locked->forceFill([
                    'status' => TenantOnboardingInvitationStatus::Accepted,
                    'accepted_at' => $acceptedAt,
                    'accepted_tenant_id' => $tenant->getKey(),
                ])->save();

                $this->audit->record('platform.onboarding_invitation.accepted', $tenant, [], [
                    'invitation_id' => $locked->getKey(),
                    'plan_code' => $plan->code,
                    'trial_days' => $locked->trial_days,
                ]);

                return compact('tenant', 'agency', 'owner');
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'company_slug' => __('Cet identifiant d’entreprise ou cette adresse e-mail est déjà utilisé.'),
                ]);
            }

            throw $exception;
        }

        if ($expired || $result === null) {
            throw ValidationException::withMessages(['invitation' => __('Cette invitation a expiré.')]);
        }

        return $result;
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
