<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\Platform\PlatformAdminGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionSaasSubscription
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
    ) {}

    public function handle(
        SaasSubscription $subscription,
        TenantSubscriptionStatus $status,
        int $actorId,
    ): SaasSubscription {
        $this->platformAdmin->actor($actorId);

        return DB::transaction(function () use ($subscription, $status, $actorId): SaasSubscription {
            $locked = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
            $oldStatus = $locked->status;

            if ($oldStatus === $status) {
                return $locked;
            }
            if (! $this->allows($oldStatus, $status)) {
                throw ValidationException::withMessages(['status' => 'Cette transition d’abonnement n’est pas autorisée.']);
            }

            $locked->forceFill([
                'status' => $status,
                'suspended_at' => $status === TenantSubscriptionStatus::Suspended ? now() : null,
                'cancelled_at' => $status === TenantSubscriptionStatus::Cancelled ? now() : null,
                'expired_at' => $status === TenantSubscriptionStatus::Expired ? now() : null,
                'updated_by' => $actorId,
            ])->save();

            $this->audit->record('platform.subscription.status_changed', $locked, [
                'status' => $oldStatus->value,
            ], [
                'status' => $status->value,
            ]);

            return $locked->refresh();
        });
    }

    private function allows(TenantSubscriptionStatus $from, TenantSubscriptionStatus $to): bool
    {
        $allowed = match ($from) {
            TenantSubscriptionStatus::Trialing => [
                TenantSubscriptionStatus::Active,
                TenantSubscriptionStatus::Suspended,
                TenantSubscriptionStatus::Cancelled,
                TenantSubscriptionStatus::Expired,
            ],
            TenantSubscriptionStatus::Active => [
                TenantSubscriptionStatus::PastDue,
                TenantSubscriptionStatus::Suspended,
                TenantSubscriptionStatus::Cancelled,
                TenantSubscriptionStatus::Expired,
            ],
            TenantSubscriptionStatus::PastDue => [
                TenantSubscriptionStatus::Active,
                TenantSubscriptionStatus::Suspended,
                TenantSubscriptionStatus::Cancelled,
                TenantSubscriptionStatus::Expired,
            ],
            TenantSubscriptionStatus::Suspended => [
                TenantSubscriptionStatus::Active,
                TenantSubscriptionStatus::PastDue,
                TenantSubscriptionStatus::Cancelled,
                TenantSubscriptionStatus::Expired,
            ],
            TenantSubscriptionStatus::Cancelled, TenantSubscriptionStatus::Expired => [],
        };

        return in_array($to, $allowed, true);
    }
}
