<?php

namespace App\Actions\PlatformBilling;

use App\Enums\PlatformBilling\SaasPaymentEntryType;
use App\Enums\PlatformBilling\SaasPaymentMethod;
use App\Models\PlatformBilling\SaasPayment;
use App\Support\Audit\AuditRecorder;
use App\Support\Platform\PlatformAdminGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseSaasPayment
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PlatformAdminGuard $platformAdmin,
    ) {}

    public function handle(SaasPayment $payment, array $data, int $actorId): SaasPayment
    {
        $this->platformAdmin->actor($actorId);
        $this->rejectUnexpected($data, [
            'reason', 'reference', 'idempotency_key', 'occurred_at', 'note', 'cmi_refund_confirmed',
        ]);
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif de contrepassation est obligatoire.']);
        }
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'La demande n’a pas pu être sécurisée. Rechargez la page puis réessayez.']);
        }
        $reference = $this->nullableText($data['reference'] ?? null);
        $note = $this->nullableText($data['note'] ?? null);
        $cmiRefundConfirmed = filter_var(
            $data['cmi_refund_confirmed'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        try {
            return DB::transaction(function () use (
                $payment,
                $data,
                $actorId,
                $reason,
                $idempotencyKey,
                $reference,
                $note,
                $cmiRefundConfirmed,
            ): SaasPayment {
                $original = SaasPayment::query()->whereKey($payment)->lockForUpdate()->firstOrFail();
                if ($original->entry_type !== SaasPaymentEntryType::Payment) {
                    throw ValidationException::withMessages(['payment' => 'Seul un paiement SaaS original peut être contrepassé.']);
                }
                if ($original->payment_method === SaasPaymentMethod::Cmi && ! $cmiRefundConfirmed) {
                    throw ValidationException::withMessages([
                        'cmi_refund_confirmed' => 'Confirmez que le remboursement a réussi dans le portail marchand CMI.',
                    ]);
                }
                if ($original->payment_method === SaasPaymentMethod::Cmi && $reference === null) {
                    throw ValidationException::withMessages([
                        'reference' => 'La référence du remboursement confirmé par CMI est obligatoire.',
                    ]);
                }

                $occurredAt = isset($data['occurred_at'])
                    ? CarbonImmutable::parse((string) $data['occurred_at'])
                    : CarbonImmutable::now();
                if ($occurredAt->lt($original->occurred_at)) {
                    throw ValidationException::withMessages([
                        'occurred_at' => 'La date de contrepassation ne peut pas précéder celle du paiement original.',
                    ]);
                }

                $this->lockIdempotency($original->tenant_id, $idempotencyKey);
                $existing = SaasPayment::query()
                    ->where('tenant_id', $original->tenant_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertSameReversal(
                        $existing,
                        $original,
                        $reason,
                        $reference,
                        $note,
                        $data['occurred_at'] ?? null,
                    );

                    return $existing;
                }
                if ($reference !== null && SaasPayment::query()
                    ->whereRaw('lower(reference) = lower(?)', [$reference])
                    ->exists()) {
                    throw ValidationException::withMessages(['reference' => 'Cette référence administrative est déjà utilisée.']);
                }

                if (SaasPayment::query()->where('reversal_of_id', $original->getKey())->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['payment' => 'Ce paiement SaaS a déjà été contrepassé.']);
                }

                $reversal = new SaasPayment;
                $reversal->forceFill([
                    'entry_type' => SaasPaymentEntryType::Reversal,
                    'payment_method' => $original->payment_method,
                    'amount' => $original->amount,
                    'currency' => $original->currency,
                    'reference' => $reference,
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => $occurredAt,
                    'reversal_of_id' => $original->getKey(),
                    'reason' => $reason,
                    'note' => $note,
                    'created_by' => $actorId,
                ]);
                $reversal->tenant_id = $original->tenant_id;
                $reversal->saas_subscription_id = $original->saas_subscription_id;
                $reversal->save();

                $this->audit->record('platform.saas_payment.reversed', $reversal, [], [
                    'entry_type' => SaasPaymentEntryType::Reversal->value,
                    'payment_method' => $reversal->payment_method->value,
                    'amount' => $reversal->amount,
                    'currency' => $reversal->currency,
                    'reversal_of_id' => $original->getKey(),
                    'cmi_refund_confirmed' => $original->payment_method === SaasPaymentMethod::Cmi,
                    'gateway_refund_reference' => $original->payment_method === SaasPaymentMethod::Cmi
                        ? $reference
                        : null,
                ]);

                return $reversal;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23514'
                && str_contains($exception->getMessage(), 'A SaaS payment reversal cannot predate its original')) {
                throw ValidationException::withMessages([
                    'occurred_at' => 'La date de contrepassation ne peut pas précéder celle du paiement original.',
                ]);
            }

            if ((string) $exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'saas_payments_reference_unique_idx')) {
                throw ValidationException::withMessages(['reference' => 'Cette référence administrative est déjà utilisée.']);
            }

            throw $exception;
        }
    }

    private function assertSameReversal(
        SaasPayment $existing,
        SaasPayment $original,
        string $reason,
        ?string $reference,
        ?string $note,
        mixed $occurredAt,
    ): void {
        $same = $existing->entry_type === SaasPaymentEntryType::Reversal
            && $existing->reversal_of_id === $original->getKey()
            && $existing->reason === $reason
            && $existing->reference === $reference
            && $existing->note === $note;

        if ($same && $occurredAt !== null) {
            $same = $existing->occurred_at->equalTo(CarbonImmutable::parse((string) $occurredAt));
        }

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Cette demande a déjà été envoyée avec des informations différentes. Rechargez la page puis réessayez.',
            ]);
        }
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
            throw ValidationException::withMessages([$unexpected[0] => 'Ce champ n’est pas autorisé.']);
        }
    }
}
