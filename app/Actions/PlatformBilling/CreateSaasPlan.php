<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\SaasBillingInterval;
use App\Models\PlatformBilling\SaasPlan;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\SaasPlanEntitlements;
use App\Support\Platform\PlatformAdminGuard;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateSaasPlan
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
        private readonly SaasPlanEntitlements $entitlements,
    ) {}

    public function handle(array $data, int $actorId): SaasPlan
    {
        $this->platformAdmin->actor($actorId);
        $this->rejectUnexpected($data, [
            'code', 'name', 'description', 'billing_interval', 'price_amount', 'currency', 'features', 'is_active',
            'entitlements_configured', 'max_agencies', 'max_users', 'max_vehicles',
            'monthly_intelligence_runs', 'intelligence_capabilities',
        ]);

        $interval = SaasBillingInterval::tryFrom((string) ($data['billing_interval'] ?? ''));
        if ($interval === null) {
            throw ValidationException::withMessages(['billing_interval' => 'La périodicité du plan est invalide.']);
        }

        $price = $this->money($data['price_amount'] ?? null);
        $entitlements = $this->entitlements->fromInput($data);

        return DB::transaction(function () use ($data, $actorId, $interval, $price, $entitlements): SaasPlan {
            $plan = new SaasPlan;
            $plan->forceFill([
                'code' => strtolower(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'description' => $this->nullableText($data['description'] ?? null),
                'billing_interval' => $interval,
                'price_amount' => $price,
                'currency' => strtoupper(trim((string) ($data['currency'] ?? 'MAD'))),
                'features' => array_values($data['features'] ?? []),
                'entitlements' => $entitlements,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ])->save();

            $this->audit->record('platform.saas_plan.created', $plan, [], [
                'code' => $plan->code,
                'billing_interval' => $plan->billing_interval->value,
                'price_amount' => $plan->price_amount,
                'currency' => $plan->currency,
                'is_active' => $plan->is_active,
                'entitlements' => $plan->entitlements,
            ]);

            return $plan;
        });
    }

    private function money(mixed $value): string
    {
        try {
            return DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits((string) $value));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['price_amount' => 'Le prix doit être un montant décimal valide.']);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function rejectUnexpected(array $data, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($data), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => 'Ce champ n’est pas autorisé.']);
        }
    }
}
