<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasPlan;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\Tenant;
use App\Support\Audit\AuditRecorder;
use App\Support\Platform\PlatformAdminGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignSaasSubscription
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
    ) {}

    public function handle(Tenant $tenant, SaasPlan $plan, array $data, int $actorId): SaasSubscription
    {
        $this->platformAdmin->actor($actorId);
        $this->rejectUnexpected($data, [
            'status', 'starts_at', 'ends_at', 'trial_ends_at', 'next_renewal_at', 'admin_note',
        ]);
        $status = TenantSubscriptionStatus::tryFrom((string) ($data['status'] ?? ''));
        if ($status === null) {
            throw ValidationException::withMessages(['status' => 'Le statut d’abonnement est invalide.']);
        }
        if (! in_array($status, [TenantSubscriptionStatus::Trialing, TenantSubscriptionStatus::Active], true)) {
            throw ValidationException::withMessages(['status' => 'Seuls un essai ou un abonnement actif peuvent être créés.']);
        }
        if ($status === TenantSubscriptionStatus::Trialing && empty($data['trial_ends_at'])) {
            throw ValidationException::withMessages(['trial_ends_at' => 'La fin de la période d’essai est obligatoire.']);
        }

        return DB::transaction(function () use ($tenant, $plan, $data, $actorId, $status): SaasSubscription {
            $lockedTenant = Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();
            $lockedPlan = SaasPlan::query()->whereKey($plan)->lockForUpdate()->firstOrFail();

            if (! $lockedPlan->is_active) {
                throw ValidationException::withMessages(['saas_plan_id' => 'Ce plan SaaS est inactif.']);
            }

            $currentStatuses = array_map(
                static fn (TenantSubscriptionStatus $case): string => $case->value,
                TenantSubscriptionStatus::current(),
            );
            if (SaasSubscription::query()
                ->where('tenant_id', $lockedTenant->getKey())
                ->whereIn('status', $currentStatuses)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages(['subscription' => 'Cette entreprise possède déjà un abonnement courant.']);
            }

            $subscription = new SaasSubscription;
            $subscription->forceFill([
                'saas_plan_id' => $lockedPlan->getKey(),
                'status' => $status,
                'billing_interval' => $lockedPlan->billing_interval,
                'price_amount' => $lockedPlan->price_amount,
                'currency' => $lockedPlan->currency,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'] ?? null,
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
                'next_renewal_at' => $data['next_renewal_at'] ?? null,
                'suspended_at' => $status === TenantSubscriptionStatus::Suspended ? now() : null,
                'cancelled_at' => $status === TenantSubscriptionStatus::Cancelled ? now() : null,
                'expired_at' => $status === TenantSubscriptionStatus::Expired ? now() : null,
                'admin_note' => $this->nullableText($data['admin_note'] ?? null),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $subscription->tenant()->associate($lockedTenant);
            $subscription->save();

            $this->audit->record('platform.subscription.created', $subscription, [], [
                'plan_code' => $lockedPlan->code,
                'status' => $status->value,
                'billing_interval' => $subscription->billing_interval->value,
                'price_amount' => $subscription->price_amount,
                'currency' => $subscription->currency,
            ]);

            return $subscription;
        });
    }

    private function rejectUnexpected(array $data, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($data), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => 'Ce champ n’est pas autorisé.']);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
