<?php

namespace App\Actions\PlatformBilling;

use App\Models\PlatformBilling\SaasPlan;
use App\Support\Audit\AuditRecorder;
use App\Support\Platform\PlatformAdminGuard;
use App\Support\PlatformBilling\SaasPlanEntitlements;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class UpdateSaasPlan
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
        private readonly SaasPlanEntitlements $entitlements,
    ) {}

    public function handle(SaasPlan $plan, array $data, int $actorId): SaasPlan
    {
        $this->platformAdmin->actor($actorId);
        $this->rejectUnexpected($data, [
            'name', 'description', 'price_amount', 'currency', 'features', 'is_active',
            'entitlements_configured', 'max_agencies', 'max_users', 'max_vehicles',
            'monthly_intelligence_runs', 'intelligence_capabilities',
        ]);
        $price = $this->money($data['price_amount'] ?? null);

        return DB::transaction(function () use ($plan, $data, $actorId, $price): SaasPlan {
            $locked = SaasPlan::query()->whereKey($plan)->lockForUpdate()->firstOrFail();
            $old = $locked->only(['name', 'description', 'price_amount', 'currency', 'features', 'entitlements', 'is_active']);
            $description = trim((string) ($data['description'] ?? ''));
            $entitlements = $this->entitlements->fromInput($data, $locked->entitlements);

            $locked->forceFill([
                'name' => trim((string) $data['name']),
                'description' => $description === '' ? null : $description,
                'price_amount' => $price,
                'currency' => strtoupper(trim((string) ($data['currency'] ?? 'MAD'))),
                'features' => array_values($data['features'] ?? []),
                'entitlements' => $entitlements,
                'is_active' => (bool) $data['is_active'],
                'updated_by' => $actorId,
            ])->save();

            $action = match (true) {
                (bool) $old['is_active'] && ! $locked->is_active => 'platform.saas_plan.deactivated',
                ! (bool) $old['is_active'] && $locked->is_active => 'platform.saas_plan.activated',
                default => 'platform.saas_plan.updated',
            };

            $this->audit->record(
                $action,
                $locked,
                $old,
                $locked->only(array_keys($old)),
            );

            return $locked->refresh();
        });
    }

    private function money(mixed $value): string
    {
        try {
            return DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits((string) $value));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['price_amount' => __('Le prix doit être un montant décimal valide.')]);
        }
    }

    private function rejectUnexpected(array $data, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($data), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([$unexpected[0] => __('Ce champ n’est pas autorisé.')]);
        }
    }
}
