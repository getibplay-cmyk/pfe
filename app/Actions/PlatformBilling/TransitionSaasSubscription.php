<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\Platform\PlatformAdminGuard;
use App\Support\PlatformBilling\SaasBillingLock;
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
            app(SaasBillingLock::class)->tenant($subscription->tenant_id);
            $locked = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
            $oldStatus = $locked->status;

            if ($oldStatus === $status) {
                if ($status === TenantSubscriptionStatus::Suspended && $locked->billing_suspended) {
                    $locked->forceFill(['billing_suspended' => false, 'updated_by' => $actorId])->save();
                    $this->audit->record('platform.subscription.administrative_hold', $locked, [], ['status' => 'suspended']);
                }

                return $locked;
            }
            if (! $this->allows($oldStatus, $status)) {
                throw ValidationException::withMessages(['status' => 'Cette transition d’abonnement n’est pas autorisée.']);
            }
            if ($status->isTerminal()) {
                if ($locked->invoices()->where('status', 'open')->exists()) {
                    throw ValidationException::withMessages(['status' => 'Régularisez les factures ouvertes avant de clôturer cet abonnement.']);
                }
                app(SaasBillingLock::class)->ensureNoCheckout($locked->getKey());
                foreach (SaasSubscription::query()->where('previous_subscription_id', $locked->getKey())->where('status', 'pending_payment')->get() as $pending) {
                    app(SaasBillingLock::class)->ensureNoCheckout($pending->getKey());
                }
            }

            $locked->forceFill([
                'status' => $status,
                'billing_suspended' => false,
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
            TenantSubscriptionStatus::PendingPayment => [],
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
