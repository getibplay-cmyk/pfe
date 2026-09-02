<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\SaasPaymentAttemptStatus;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasPaymentAttempt;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\Cmi\CmiConfiguration;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartCmiCheckout
{
    public function __construct(
        private readonly CmiConfiguration $configuration,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(SaasSubscription $subscription, User $actor, string $idempotencyKey): SaasPaymentAttempt
    {
        $this->configuration->assertReady();
        abort_unless($actor->isTenantOwner() && $actor->tenant_id !== null, 403);

        $idempotencyKey = trim($idempotencyKey);

        return DB::transaction(function () use ($subscription, $actor, $idempotencyKey): SaasPaymentAttempt {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['cmi-checkout:'.$actor->tenant_id.':'.$idempotencyKey],
            );

            $locked = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
            abort_unless($locked->tenant_id === $actor->tenant_id, 404);

            $existing = SaasPaymentAttempt::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->saas_subscription_id !== $locked->getKey()) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Cette demande a déjà été utilisée pour un autre abonnement.',
                    ]);
                }

                return $existing;
            }

            if (! in_array($locked->status, TenantSubscriptionStatus::current(), true)) {
                throw ValidationException::withMessages(['payment' => 'Cet abonnement ne peut plus être réglé.']);
            }
            if ($locked->currency !== config('platform_billing.cmi.currency')) {
                throw ValidationException::withMessages(['payment' => 'CMI est actuellement configuré pour les paiements en MAD uniquement.']);
            }
            if (DecimalMoney::toMinorUnits($locked->price_amount) <= 0) {
                throw ValidationException::withMessages(['payment' => 'Aucun paiement n’est requis pour cette offre.']);
            }

            $attempt = new SaasPaymentAttempt;
            $attempt->forceFill([
                'tenant_id' => $locked->tenant_id,
                'saas_subscription_id' => $locked->getKey(),
                'provider' => 'cmi',
                'merchant_order_id' => 'BS-'.strtoupper((string) Str::ulid()),
                'status' => SaasPaymentAttemptStatus::Pending,
                'amount' => $locked->price_amount,
                'currency' => $locked->currency,
                'idempotency_key' => $idempotencyKey,
                'gateway_transaction_id' => null,
                'gateway_response_code' => null,
                'initiated_by' => $actor->getKey(),
                'expires_at' => now()->addMinutes((int) config('platform_billing.cmi.attempt_ttl_minutes')),
                'resolved_at' => null,
                'paid_at' => null,
            ])->save();

            $this->audit->record('platform.saas_payment.cmi_started', $attempt, [], [
                'provider' => 'cmi',
                'merchant_order_id' => $attempt->merchant_order_id,
                'amount' => $attempt->amount,
                'currency' => $attempt->currency,
            ]);

            return $attempt;
        });
    }
}
