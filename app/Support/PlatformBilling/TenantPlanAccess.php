<?php

namespace App\Support\PlatformBilling;

use App\Enums\IntelligenceCapability;
use App\Enums\TenantStatus;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TenantPlanAccess
{
    private const CURRENT_STATUSES = ['trialing', 'active', 'past_due', 'suspended'];

    /** @var array<string, string> */
    private const RESOURCE_QUOTAS = [
        'agencies' => 'max_agencies',
        'users' => 'max_users',
        'vehicles' => 'max_vehicles',
        'intelligence_runs' => 'monthly_intelligence_runs',
    ];

    /** @var array<string, string> */
    private const RESOURCE_LABELS = [
        'agencies' => 'agences actives',
        'users' => 'utilisateurs actifs',
        'vehicles' => 'véhicules',
        'intelligence_runs' => 'analyses intelligentes du mois',
    ];

    /** @var list<string> */
    private const INTELLIGENCE_TABLES = [
        'demand_forecast_execution_runs',
        'fleet_reallocation_runs',
        'fleet_reallocation_planning_runs',
        'rental_usage_anomaly_runs',
        'vehicle_color_prediction_runs',
        'vehicle_plate_prediction_runs',
        'vehicle_damage_prediction_runs',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SaasPlanEntitlements $entitlements,
    ) {}

    /**
     * @return array{subscription: ?SaasSubscription, legacy: bool, available: bool, reason: string, entitlements: array<string, mixed>}
     */
    public function state(?int $tenantId = null, bool $lockForUpdate = false): array
    {
        $tenantId ??= $this->context->tenantId();
        $tenantActive = DB::table('tenants')
            ->where('id', $tenantId)
            ->where('status', TenantStatus::Active->value)
            ->whereNull('deleted_at')
            ->exists();
        $query = SaasSubscription::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::CURRENT_STATUSES)
            ->latest('starts_at');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $subscription = $query->first();

        if ($subscription === null) {
            $hasHistory = SaasSubscription::query()->where('tenant_id', $tenantId)->exists();

            return [
                'subscription' => null,
                'legacy' => ! $hasHistory,
                'available' => $tenantActive && ! $hasHistory,
                'reason' => match (true) {
                    ! $tenantActive => 'Cette entreprise n’est pas active.',
                    $hasHistory => 'Aucun abonnement SaaS actif ne permet une nouvelle utilisation.',
                    default => 'Compte historique sans limitation contractuelle.',
                },
                'entitlements' => $this->entitlements->defaults(),
            ];
        }

        $status = $subscription->status->value;
        $notStarted = $subscription->starts_at->isFuture();
        $trialExpired = $status === 'trialing'
            && ($subscription->trial_ends_at === null || $subscription->trial_ends_at->isPast());
        $accessEnd = $subscription->ends_at;
        if ($status === 'past_due' && ($subscription->auto_renew || $subscription->invoices()->where('status', 'open')->exists())) {
            $openInvoice = $subscription->invoices()->where('status', 'open')->oldest('due_at')->first();
            $accessEnd = ($openInvoice?->due_at ?? $accessEnd)?->addDays((int) config('platform_billing.grace_days'));
        }
        $periodExpired = $accessEnd?->lessThanOrEqualTo(now()) ?? false;
        $available = $tenantActive
            && $status !== 'suspended'
            && ! $notStarted
            && ! $trialExpired
            && ! $periodExpired;

        return [
            'subscription' => $subscription,
            'legacy' => false,
            'available' => $available,
            'reason' => match (true) {
                ! $tenantActive => 'Cette entreprise n’est pas active.',
                $status === 'suspended' => 'L’abonnement SaaS est suspendu.',
                $notStarted => 'La période d’abonnement n’a pas encore commencé.',
                $trialExpired => 'La période d’essai est terminée.',
                $periodExpired => 'La période d’abonnement est terminée.',
                $status === 'past_due' => 'L’abonnement reste utilisable pendant la période de régularisation.',
                default => 'Abonnement SaaS utilisable.',
            },
            'entitlements' => $this->entitlements->normalize($subscription->entitlements),
        ];
    }

    /** @return array{resource: string, label: string, used: int, limit: ?int, remaining: ?int, allowed: bool, legacy: bool, reason: string} */
    public function quota(string $resource, ?int $tenantId = null): array
    {
        $quotaKey = self::RESOURCE_QUOTAS[$resource] ?? null;
        if ($quotaKey === null) {
            throw new \InvalidArgumentException('Unknown SaaS quota resource.');
        }

        $tenantId ??= $this->context->tenantId();
        $state = $this->state($tenantId);

        return $this->quotaFromState($resource, $tenantId, $state);
    }

    public function ensureCanCreate(string $resource, ?int $tenantId = null): void
    {
        $this->ensureCanConsume($resource, $tenantId);
    }

    public function ensureCanUseIntelligence(
        IntelligenceCapability $capability,
        ?int $tenantId = null,
    ): void {
        $this->ensureCanConsume('intelligence_runs', $tenantId, $capability);
    }

    public function allowsCapability(IntelligenceCapability $capability, ?int $tenantId = null): bool
    {
        $tenantId ??= $this->context->tenantId();
        $state = $this->state($tenantId);
        if (! $state['available']) {
            return false;
        }
        if (! $state['legacy'] && ! in_array($capability->value, $state['entitlements']['intelligence_capabilities'], true)) {
            return false;
        }

        return $this->quotaFromState('intelligence_runs', $tenantId, $state)['allowed'];
    }

    /**
     * @param  array{subscription: ?SaasSubscription, legacy: bool, available: bool, reason: string, entitlements: array<string, mixed>}  $state
     * @return array{resource: string, label: string, used: int, limit: ?int, remaining: ?int, allowed: bool, legacy: bool, reason: string}
     */
    private function quotaFromState(string $resource, int $tenantId, array $state): array
    {
        $quotaKey = self::RESOURCE_QUOTAS[$resource] ?? null;
        if ($quotaKey === null) {
            throw new \InvalidArgumentException('Unknown SaaS quota resource.');
        }
        $limit = $state['legacy'] ? null : $state['entitlements'][$quotaKey];
        $pending = SaasSubscription::query()->where('tenant_id', $tenantId)->where('status', 'pending_payment')
            ->where('change_expires_at', '>', now())->first();
        if ($pending !== null) {
            $reserved = $this->entitlements->normalize($pending->entitlements)[$quotaKey];
            $limit = $reserved === null ? $limit : ($limit === null ? $reserved : min($limit, $reserved));
        }
        $used = $this->usage($resource, $tenantId);
        $remaining = $limit === null ? null : max(0, $limit - $used);

        return [
            'resource' => $resource,
            'label' => self::RESOURCE_LABELS[$resource],
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'allowed' => $state['available'] && ($limit === null || $used < $limit),
            'legacy' => $state['legacy'],
            'reason' => $state['reason'],
        ];
    }

    /** @return list<array{resource: string, label: string, used: int, limit: ?int, remaining: ?int, allowed: bool, legacy: bool, reason: string}> */
    public function quotaSummary(?int $tenantId = null): array
    {
        return collect(array_keys(self::RESOURCE_QUOTAS))
            ->map(fn (string $resource): array => $this->quota($resource, $tenantId))
            ->values()
            ->all();
    }

    private function ensureCanConsume(
        string $resource,
        ?int $tenantId = null,
        ?IntelligenceCapability $capability = null,
    ): void {
        $tenantId ??= $this->context->tenantId();
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('SaaS quotas must be enforced inside a database transaction.');
        }

        $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['status', 'deleted_at']);
        if ($tenant === null || $tenant->deleted_at !== null || $tenant->status !== TenantStatus::Active->value) {
            throw ValidationException::withMessages(['plan' => 'Cette entreprise n’est pas active.']);
        }

        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            ["saas-quota:{$tenantId}:{$resource}"],
        );
        $state = $this->state($tenantId, true);
        $quota = $this->quotaFromState($resource, $tenantId, $state);
        if (! $state['available']) {
            throw ValidationException::withMessages(['plan' => $state['reason']]);
        }
        if ($capability !== null
            && ! $state['legacy']
            && ! in_array($capability->value, $state['entitlements']['intelligence_capabilities'], true)) {
            throw ValidationException::withMessages([
                'plan' => 'Cette assistance intelligente n’est pas incluse dans le plan courant.',
            ]);
        }

        if (! $quota['allowed']) {
            $message = $quota['limit'] !== null && $quota['used'] >= $quota['limit']
                ? "Le quota du plan est atteint : {$quota['used']} {$quota['label']} sur {$quota['limit']}."
                : $quota['reason'];
            throw ValidationException::withMessages(['plan' => $message]);
        }
    }

    public function ensureEntitlementsFit(int $tenantId, array $entitlements): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Plan changes require the tenant transaction lock.');
        }
        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
        $limits = $this->entitlements->normalize($entitlements);
        foreach (self::RESOURCE_QUOTAS as $resource => $quotaKey) {
            if ($limits[$quotaKey] !== null && $this->usage($resource, $tenantId) > $limits[$quotaKey]) {
                throw ValidationException::withMessages(['saas_plan_id' => 'La formule ne couvre pas l’utilisation actuelle : '.self::RESOURCE_LABELS[$resource].'.']);
            }
        }
    }

    private function usage(string $resource, int $tenantId): int
    {
        return match ($resource) {
            'agencies' => DB::table('agencies')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->count(),
            'users' => DB::table('users')->where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'vehicles' => DB::table('vehicles')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
            'intelligence_runs' => $this->intelligenceUsage($tenantId),
            default => throw new \InvalidArgumentException('Unknown SaaS quota resource.'),
        };
    }

    private function intelligenceUsage(int $tenantId): int
    {
        $timezone = (string) config('app.timezone');
        $startsAt = CarbonImmutable::now($timezone)->startOfMonth()->utc();
        $endsAt = $startsAt->addMonth();

        return collect(self::INTELLIGENCE_TABLES)->sum(
            fn (string $table): int => DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('requested_at', '>=', $startsAt)
                ->where('requested_at', '<', $endsAt)
                ->count(),
        );
    }
}
