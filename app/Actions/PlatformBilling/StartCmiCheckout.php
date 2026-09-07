<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\SaasBillingInterval;
use App\Enums\PlatformBilling\SaasPaymentAttemptStatus;
use App\Enums\PlatformBilling\SaasPaymentEntryType;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPaymentAttempt;
use App\Models\PlatformBilling\SaasSubscription;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\Cmi\CmiConfiguration;
use App\Support\PlatformBilling\SaasBillingLock;
use App\Support\Pricing\DecimalMoney;
use Carbon\CarbonImmutable;
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
        abort_unless($actor->is_active && ! $actor->is_platform_admin && $actor->hasVerifiedEmail()
            && $actor->isTenantOwner() && ($actor->role?->is_active ?? false) && $actor->tenant_id !== null, 403);

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'La clé de demande est invalide.']);
        }

        return DB::transaction(function () use ($subscription, $actor, $idempotencyKey): SaasPaymentAttempt {
            app(SaasBillingLock::class)->activeTenant($actor->tenant_id);
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['cmi-checkout:'.$actor->tenant_id.':'.$idempotencyKey],
            );

            $locked = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
            abort_unless($locked->tenant_id === $actor->tenant_id, 404);

            $existing = SaasPaymentAttempt::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                if ($existing->saas_subscription_id !== $locked->getKey()) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Cette demande a déjà été utilisée pour un autre abonnement.',
                    ]);
                }

                return $existing;
            }

            if ((! in_array($locked->status, TenantSubscriptionStatus::current(), true)
                && $locked->status !== TenantSubscriptionStatus::PendingPayment)
                || ($locked->status === TenantSubscriptionStatus::Suspended && ! $locked->billing_suspended)) {
                throw ValidationException::withMessages(['payment' => 'Cet abonnement ne peut plus être réglé.']);
            }
            if ($locked->currency !== config('platform_billing.cmi.currency')) {
                throw ValidationException::withMessages(['payment' => 'CMI est actuellement configuré pour les paiements en MAD uniquement.']);
            }
            if (SaasSubscription::query()->where('previous_subscription_id', $locked->getKey())->where('status', 'pending_payment')->exists()) {
                throw ValidationException::withMessages(['payment' => 'Réglez ou annulez le changement de formule en cours avant un autre paiement.']);
            }
            if (DecimalMoney::toMinorUnits($locked->price_amount) <= 0) {
                throw ValidationException::withMessages(['payment' => 'Aucun paiement n’est requis pour cette offre.']);
            }

            $now = CarbonImmutable::now();
            app(SaasBillingLock::class)->expireAttempts($locked->getKey());
            $outstanding = SaasPaymentAttempt::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('saas_subscription_id', $locked->getKey())
                ->where('provider', 'cmi')
                ->where('status', SaasPaymentAttemptStatus::Pending->value)
                ->where('expires_at', '>', $now)
                ->latest('created_at')
                ->first();
            if ($outstanding !== null) {
                return $outstanding;
            }

            $invoice = $locked->invoices()->where('status', 'open')->oldest('period_starts_at')->lockForUpdate()->first();
            if ($invoice !== null) {
                app(SaasInvoiceLifecycle::class)->payable($invoice);
            } elseif ($locked->invoices()->exists() || $locked->status === TenantSubscriptionStatus::PendingPayment) {
                throw ValidationException::withMessages(['payment' => 'Aucune facture ouverte ne nécessite de paiement.']);
            }
            $billingPeriodStartsAt = $invoice?->period_starts_at ?? $this->billingPeriodStartsAt($locked, $now);
            $alreadySettled = $invoice === null && SaasPayment::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('saas_subscription_id', $locked->getKey())
                ->where('entry_type', SaasPaymentEntryType::Payment->value)
                ->where('occurred_at', '>=', $billingPeriodStartsAt)
                ->whereDoesntHave('reversal')
                ->first(['id']) !== null;
            if ($alreadySettled) {
                throw ValidationException::withMessages([
                    'payment' => 'La période de facturation courante est déjà réglée.',
                ]);
            }

            $attempt = new SaasPaymentAttempt;
            $attempt->forceFill([
                'tenant_id' => $locked->tenant_id,
                'saas_subscription_id' => $locked->getKey(),
                'saas_invoice_id' => $invoice?->getKey(),
                'provider' => 'cmi',
                'merchant_order_id' => 'BS-'.strtoupper((string) Str::ulid()),
                'status' => SaasPaymentAttemptStatus::Pending,
                'amount' => $locked->price_amount,
                'currency' => $locked->currency,
                'idempotency_key' => $idempotencyKey,
                'gateway_transaction_id' => null,
                'gateway_response_code' => null,
                'initiated_by' => $actor->getKey(),
                'expires_at' => $locked->change_expires_at !== null && $locked->status === TenantSubscriptionStatus::PendingPayment
                    ? $now->addMinutes((int) config('platform_billing.cmi.attempt_ttl_minutes'))->min($locked->change_expires_at)
                    : $now->addMinutes((int) config('platform_billing.cmi.attempt_ttl_minutes')),
                'resolved_at' => null,
                'paid_at' => null,
            ])->save();

            $this->audit->record('platform.saas_payment.cmi_started', $locked, [], [
                'payment_attempt_id' => $attempt->getKey(),
                'provider' => 'cmi',
                'merchant_order_id' => $attempt->merchant_order_id,
                'amount' => $attempt->amount,
                'currency' => $attempt->currency,
                'billing_period_starts_at' => $billingPeriodStartsAt->toIso8601String(),
            ]);

            return $attempt;
        });
    }

    private function billingPeriodStartsAt(
        SaasSubscription $subscription,
        CarbonImmutable $now,
    ): CarbonImmutable {
        $periodStart = $subscription->starts_at;
        if ($periodStart->greaterThan($now)) {
            return $periodStart;
        }

        for ($period = 0; $period < 1200; $period++) {
            $nextPeriod = $subscription->billing_interval === SaasBillingInterval::Annual
                ? $periodStart->addYearNoOverflow()
                : $periodStart->addMonthNoOverflow();
            if ($nextPeriod->greaterThan($now)) {
                return $periodStart;
            }

            $periodStart = $nextPeriod;
        }

        throw ValidationException::withMessages(['payment' => 'La période historique doit être régularisée par l’administrateur.']);
    }
}
