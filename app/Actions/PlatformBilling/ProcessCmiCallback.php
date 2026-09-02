<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\SaasBillingInterval;
use App\Enums\PlatformBilling\SaasGatewayEventResult;
use App\Enums\PlatformBilling\SaasPaymentAttemptStatus;
use App\Enums\PlatformBilling\SaasPaymentEntryType;
use App\Enums\PlatformBilling\SaasPaymentMethod;
use App\Enums\PlatformBilling\TenantSubscriptionStatus;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasPaymentAttempt;
use App\Models\PlatformBilling\SaasPaymentGatewayEvent;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\PlatformBilling\Cmi\CmiConfiguration;
use App\Support\PlatformBilling\Cmi\CmiHostedGateway;
use App\Support\Pricing\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessCmiCallback
{
    public function __construct(
        private readonly CmiConfiguration $configuration,
        private readonly CmiHostedGateway $gateway,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, scalar|null>  $parameters
     * @return array{accepted: bool, signature_valid: bool, body: string, http_status: int}
     */
    public function handle(array $parameters): array
    {
        $this->configuration->assertVerifiable();
        $orderId = trim((string) $this->value($parameters, 'oid'));
        if ($orderId === '' || strlen($orderId) > 64) {
            return $this->result(false, false, 422);
        }

        $attempt = SaasPaymentAttempt::query()->where('merchant_order_id', $orderId)->first();
        if ($attempt === null) {
            return $this->result(false, false, 404);
        }

        $signatureValid = $this->gateway->signatureIsValid($parameters);
        $payloadDigest = $this->payloadDigest($parameters);
        $eventKey = hash('sha256', implode('|', [
            $attempt->getKey(),
            (string) $this->value($parameters, 'HASH'),
            $payloadDigest,
        ]));

        return DB::transaction(function () use (
            $attempt,
            $parameters,
            $signatureValid,
            $payloadDigest,
            $eventKey,
        ): array {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['cmi-callback:'.$eventKey],
            );
            $lockedAttempt = SaasPaymentAttempt::query()->whereKey($attempt)->lockForUpdate()->firstOrFail();
            $existingEvent = SaasPaymentGatewayEvent::query()
                ->where('provider_event_key', $eventKey)
                ->first();
            if ($existingEvent !== null) {
                return $lockedAttempt->status === SaasPaymentAttemptStatus::Paid
                    ? $this->result(true, $existingEvent->signature_valid, 200)
                    : $this->result(false, $existingEvent->signature_valid, 200);
            }

            $responseCode = trim((string) $this->value($parameters, 'ProcReturnCode'));
            if (! $signatureValid) {
                $this->recordEvent($lockedAttempt, $eventKey, $payloadDigest, false, SaasGatewayEventResult::Rejected, $responseCode);
                $this->audit->record('platform.saas_payment.cmi_callback_rejected', $lockedAttempt, [], [
                    'reason' => 'invalid_signature',
                ]);

                return $this->result(false, false, 422);
            }

            if ($lockedAttempt->status->isTerminal()) {
                $this->recordEvent($lockedAttempt, $eventKey, $payloadDigest, true, SaasGatewayEventResult::Duplicate, $responseCode);

                return $lockedAttempt->status === SaasPaymentAttemptStatus::Paid
                    ? $this->result(true, true, 200)
                    : $this->result(false, true, 200);
            }

            $localFailure = $this->validateSignedPayload($lockedAttempt, $parameters);
            if ($localFailure !== null) {
                $this->declineAttempt($lockedAttempt, $localFailure);
                $this->recordEvent($lockedAttempt, $eventKey, $payloadDigest, true, SaasGatewayEventResult::Rejected, $localFailure);
                $this->audit->record('platform.saas_payment.cmi_callback_rejected', $lockedAttempt, [], [
                    'reason' => $localFailure,
                ]);

                return $this->result(false, true, 200);
            }

            if ($responseCode !== '00') {
                $this->declineAttempt($lockedAttempt, $responseCode === '' ? 'CMI_DECLINED' : $responseCode);
                $this->recordEvent($lockedAttempt, $eventKey, $payloadDigest, true, SaasGatewayEventResult::Declined, $responseCode);
                $this->audit->record('platform.saas_payment.cmi_declined', $lockedAttempt, [], [
                    'response_code' => $responseCode,
                ]);

                return $this->result(false, true, 200);
            }

            $subscription = SaasSubscription::query()
                ->whereKey($lockedAttempt->saas_subscription_id)
                ->where('tenant_id', $lockedAttempt->tenant_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($subscription->status, TenantSubscriptionStatus::current(), true)) {
                $this->declineAttempt($lockedAttempt, 'LOCAL_SUBSCRIPTION_TERMINAL');
                $this->recordEvent($lockedAttempt, $eventKey, $payloadDigest, true, SaasGatewayEventResult::Rejected, 'LOCAL_SUBSCRIPTION_TERMINAL');

                return $this->result(false, true, 200);
            }

            $transactionId = trim((string) $this->value($parameters, 'TransId'));
            $reference = 'CMI:'.$transactionId;
            if (SaasPayment::query()->whereRaw('lower(reference) = lower(?)', [$reference])->exists()) {
                $this->declineAttempt($lockedAttempt, 'DUPLICATE_TRANSACTION');
                $this->recordEvent($lockedAttempt, $eventKey, $payloadDigest, true, SaasGatewayEventResult::Rejected, 'DUPLICATE_TRANSACTION');

                return $this->result(false, true, 200);
            }

            $payment = new SaasPayment;
            $payment->forceFill([
                'tenant_id' => $lockedAttempt->tenant_id,
                'saas_subscription_id' => $subscription->getKey(),
                'entry_type' => SaasPaymentEntryType::Payment,
                'payment_method' => SaasPaymentMethod::Cmi,
                'amount' => $lockedAttempt->amount,
                'currency' => $lockedAttempt->currency,
                'reference' => $reference,
                'idempotency_key' => 'cmi:'.$lockedAttempt->getKey(),
                'occurred_at' => now(),
                'reversal_of_id' => null,
                'reason' => null,
                'note' => 'Paiement confirmé par callback signé CMI.',
                'created_by' => $lockedAttempt->initiated_by,
            ])->save();

            $paidAt = CarbonImmutable::now();
            $nextRenewal = $subscription->billing_interval === SaasBillingInterval::Annual
                ? $paidAt->addYearNoOverflow()
                : $paidAt->addMonthNoOverflow();
            $endsAt = $subscription->ends_at === null || $subscription->ends_at->isBefore($nextRenewal)
                ? $nextRenewal
                : $subscription->ends_at;
            $subscription->forceFill([
                'status' => TenantSubscriptionStatus::Active,
                'ends_at' => $endsAt,
                'next_renewal_at' => $nextRenewal,
                'suspended_at' => null,
                'updated_by' => $lockedAttempt->initiated_by,
            ])->save();

            $lockedAttempt->forceFill([
                'status' => SaasPaymentAttemptStatus::Paid,
                'gateway_transaction_id' => $transactionId,
                'gateway_response_code' => '00',
                'resolved_at' => $paidAt,
                'paid_at' => $paidAt,
            ])->save();
            $this->recordEvent($lockedAttempt, $eventKey, $payloadDigest, true, SaasGatewayEventResult::Accepted, '00');
            $this->audit->record('platform.saas_payment.cmi_recorded', $payment, [], [
                'provider' => 'cmi',
                'merchant_order_id' => $lockedAttempt->merchant_order_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ]);

            return $this->result(true, true, 200);
        });
    }

    /** @param array<string, scalar|null> $parameters */
    private function validateSignedPayload(SaasPaymentAttempt $attempt, array $parameters): ?string
    {
        if (! hash_equals($attempt->merchant_order_id, trim((string) $this->value($parameters, 'oid')))) {
            return 'ORDER_MISMATCH';
        }
        if (! hash_equals($this->configuration->merchantId(), trim((string) $this->value($parameters, 'clientid')))) {
            return 'MERCHANT_MISMATCH';
        }
        if (! hash_equals((string) config('platform_billing.cmi.currency_numeric'), trim((string) $this->value($parameters, 'currency')))) {
            return 'CURRENCY_MISMATCH';
        }

        try {
            $callbackAmount = DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits(
                trim((string) $this->value($parameters, 'amount')),
            ));
        } catch (InvalidArgumentException) {
            return 'INVALID_AMOUNT';
        }
        if (! hash_equals($attempt->amount, $callbackAmount)) {
            return 'AMOUNT_MISMATCH';
        }
        if ($attempt->expires_at->isPast()) {
            return 'ATTEMPT_EXPIRED';
        }

        $transactionId = trim((string) $this->value($parameters, 'TransId'));
        if ((string) $this->value($parameters, 'ProcReturnCode') === '00'
            && ! preg_match('/\A[A-Za-z0-9._:-]{1,80}\z/', $transactionId)) {
            return 'INVALID_TRANSACTION_ID';
        }

        return null;
    }

    private function declineAttempt(SaasPaymentAttempt $attempt, string $responseCode): void
    {
        $attempt->forceFill([
            'status' => $responseCode === 'ATTEMPT_EXPIRED'
                ? SaasPaymentAttemptStatus::Expired
                : SaasPaymentAttemptStatus::Failed,
            'gateway_response_code' => substr($responseCode, 0, 50),
            'resolved_at' => now(),
            'paid_at' => null,
        ])->save();
    }

    private function recordEvent(
        SaasPaymentAttempt $attempt,
        string $eventKey,
        string $payloadDigest,
        bool $signatureValid,
        SaasGatewayEventResult $result,
        string $responseCode,
    ): void {
        $event = new SaasPaymentGatewayEvent;
        $event->forceFill([
            'saas_payment_attempt_id' => $attempt->getKey(),
            'provider' => 'cmi',
            'provider_event_key' => $eventKey,
            'payload_sha256' => $payloadDigest,
            'signature_valid' => $signatureValid,
            'processing_result' => $result,
            'response_code' => $responseCode === '' ? null : substr($responseCode, 0, 50),
            'received_at' => now(),
        ])->save();
    }

    /** @param array<string, scalar|null> $parameters */
    private function payloadDigest(array $parameters): string
    {
        uksort($parameters, 'strnatcasecmp');

        return hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, scalar|null> $parameters */
    private function value(array $parameters, string $wanted): string|int|float|bool|null
    {
        foreach ($parameters as $key => $value) {
            if (strcasecmp($key, $wanted) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{accepted: bool, signature_valid: bool, body: string, http_status: int} */
    private function result(bool $accepted, bool $signatureValid, int $httpStatus): array
    {
        return [
            'accepted' => $accepted,
            'signature_valid' => $signatureValid,
            'body' => $accepted
                ? $this->configuration->successAcknowledgement()
                : $this->configuration->failureAcknowledgement(),
            'http_status' => $httpStatus,
        ];
    }
}
