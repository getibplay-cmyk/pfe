<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\SaasPaymentEntryType;
use App\Enums\PlatformBilling\SaasPaymentMethod;
use App\Models\PlatformBilling\SaasInvoice;
use App\Models\PlatformBilling\SaasPayment;
use App\Models\PlatformBilling\SaasSubscription;
use App\Support\Audit\AuditRecorder;
use App\Support\Platform\PlatformAdminGuard;
use App\Support\PlatformBilling\SaasBillingLock;
use App\Support\Pricing\DecimalMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecordSaasPayment
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
    ) {}

    public function handle(SaasSubscription $subscription, array $data, int $actorId): SaasPayment
    {
        $this->platformAdmin->actor($actorId);
        $this->rejectUnexpected($data, [
            'payment_method', 'amount', 'reference', 'idempotency_key', 'occurred_at', 'note', 'saas_invoice_id',
        ]);
        $method = SaasPaymentMethod::tryFrom((string) ($data['payment_method'] ?? ''));
        if ($method === null) {
            throw ValidationException::withMessages(['payment_method' => __('Le mode de paiement SaaS est invalide.')]);
        }
        if ($method === SaasPaymentMethod::Cmi) {
            throw ValidationException::withMessages([
                'payment_method' => __('Un paiement CMI ne peut être créé que par un callback signé de la passerelle.'),
            ]);
        }

        $amount = $this->positiveMoney($data['amount'] ?? null);
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages(['idempotency_key' => __('La demande n’a pas pu être sécurisée. Rechargez la page puis réessayez.')]);
        }
        $reference = $this->nullableText($data['reference'] ?? null);
        $note = $this->nullableText($data['note'] ?? null);
        if (isset($data['occurred_at']) && CarbonImmutable::parse((string) $data['occurred_at'])->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => __('Un paiement ne peut pas être daté dans le futur.')]);
        }

        try {
            return DB::transaction(function () use (
                $subscription,
                $data,
                $actorId,
                $method,
                $amount,
                $idempotencyKey,
                $reference,
                $note,
            ): SaasPayment {
                app(SaasBillingLock::class)->tenant($subscription->tenant_id);
                $locked = SaasSubscription::query()->whereKey($subscription)->lockForUpdate()->firstOrFail();
                $this->lockIdempotency($locked->tenant_id, $idempotencyKey);

                $existing = SaasPayment::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if ($existing->saas_invoice_id !== ($data['saas_invoice_id'] ?? null)) {
                        throw ValidationException::withMessages(['idempotency_key' => __('Cette demande désigne une autre facture.')]);
                    }
                    $this->assertSamePayment(
                        $existing,
                        $locked,
                        $method,
                        $amount,
                        $reference,
                        $note,
                        $data['occurred_at'] ?? null,
                    );

                    return $existing;
                }
                app(SaasBillingLock::class)->ensureNoCheckout($locked->getKey());
                if (SaasSubscription::query()->where('previous_subscription_id', $locked->getKey())->where('status', 'pending_payment')->exists()) {
                    throw ValidationException::withMessages(['payment' => __('Réglez ou annulez le changement de formule en cours avant un autre paiement.')]);
                }
                $invoice = empty($data['saas_invoice_id']) ? null : SaasInvoice::query()
                    ->whereKey($data['saas_invoice_id'])->where('tenant_id', $locked->tenant_id)
                    ->where('saas_subscription_id', $locked->getKey())->lockForUpdate()->firstOrFail();
                if ($invoice !== null) {
                    app(SaasInvoiceLifecycle::class)->payable($invoice);
                    if ($invoice->amount !== $amount) {
                        throw ValidationException::withMessages(['amount' => __('Le montant doit correspondre exactement à la facture.')]);
                    }
                } elseif ($locked->invoices()->exists() || $locked->status->value === 'pending_payment') {
                    throw ValidationException::withMessages(['saas_invoice_id' => __('Sélectionnez une facture ouverte.')]);
                }
                if ($reference !== null && SaasPayment::query()
                    ->whereRaw('lower(reference) = lower(?)', [$reference])
                    ->exists()) {
                    throw ValidationException::withMessages(['reference' => __('Cette référence administrative est déjà utilisée.')]);
                }

                $payment = new SaasPayment;
                $payment->forceFill([
                    'saas_invoice_id' => $invoice?->getKey(),
                    'entry_type' => SaasPaymentEntryType::Payment,
                    'payment_method' => $method,
                    'amount' => $amount,
                    'currency' => $locked->currency,
                    'reference' => $reference,
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => $data['occurred_at'] ?? now(),
                    'reversal_of_id' => null,
                    'reason' => null,
                    'note' => $note,
                    'created_by' => $actorId,
                ]);
                $payment->tenant_id = $locked->tenant_id;
                $payment->subscription()->associate($locked);
                $payment->save();
                if ($invoice !== null) {
                    app(SaasInvoiceLifecycle::class)->settle($invoice, $payment);
                }

                $this->audit->record('platform.saas_payment.recorded', $payment, [], [
                    'entry_type' => SaasPaymentEntryType::Payment->value,
                    'payment_method' => $method->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ]);

                return $payment;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'saas_payments_reference_unique_idx')) {
                throw ValidationException::withMessages(['reference' => __('Cette référence administrative est déjà utilisée.')]);
            }

            throw $exception;
        }
    }

    private function assertSamePayment(
        SaasPayment $existing,
        SaasSubscription $subscription,
        SaasPaymentMethod $method,
        string $amount,
        ?string $reference,
        ?string $note,
        mixed $occurredAt,
    ): void {
        $same = $existing->entry_type === SaasPaymentEntryType::Payment
            && $existing->saas_subscription_id === $subscription->getKey()
            && $existing->payment_method === $method
            && DecimalMoney::toMinorUnits($existing->amount) === DecimalMoney::toMinorUnits($amount)
            && $existing->currency === $subscription->currency
            && $existing->reference === $reference
            && $existing->note === $note;

        if ($same && $occurredAt !== null) {
            $same = $existing->occurred_at->equalTo(CarbonImmutable::parse((string) $occurredAt));
        }

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('Cette demande a déjà été envoyée avec des informations différentes. Rechargez la page puis réessayez.'),
            ]);
        }
    }

    private function positiveMoney(mixed $value): string
    {
        try {
            $minor = DecimalMoney::toMinorUnits((string) $value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Le montant doit être un décimal valide.')]);
        }
        if ($minor <= 0) {
            throw ValidationException::withMessages(['amount' => __('Le montant doit être strictement positif.')]);
        }

        return DecimalMoney::fromMinorUnits($minor);
    }

    private function lockIdempotency(int $tenantId, string $key): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
            ['platform-saas-payment:'.$tenantId.':'.$key],
        );
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
            throw ValidationException::withMessages([$unexpected[0] => __('Ce champ n’est pas autorisé.')]);
        }
    }
}
