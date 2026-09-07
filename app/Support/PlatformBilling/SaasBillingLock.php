<?php

namespace App\Support\PlatformBilling;

use App\Models\PlatformBilling\SaasPaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaasBillingLock
{
    public function tenant(int $tenantId): object
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Billing operations require a transaction.');
        }

        return DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->firstOrFail();
    }

    public function activeTenant(int $tenantId): void
    {
        $tenant = $this->tenant($tenantId);
        if ($tenant->status !== 'active' || $tenant->deleted_at !== null) {
            throw ValidationException::withMessages(['subscription' => 'Cette entreprise n’est pas active.']);
        }
    }

    public function expireAttempts(int $subscriptionId): void
    {
        SaasPaymentAttempt::query()->where('saas_subscription_id', $subscriptionId)
            ->where('status', 'pending')->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'resolved_at' => now()]);
    }

    public function ensureNoCheckout(int $subscriptionId): void
    {
        $this->expireAttempts($subscriptionId);
        if (SaasPaymentAttempt::query()->where('saas_subscription_id', $subscriptionId)
            ->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['payment' => 'Un paiement CMI est en cours. Attendez sa résolution ou son expiration.']);
        }
    }
}
