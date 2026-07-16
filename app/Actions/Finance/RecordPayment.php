<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Finance\FinancialIdempotencyGuard;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    public function __construct(
        private GenerateBusinessNumber $numbers,
        private AuditRecorder $audit,
        private FinancialIdempotencyGuard $idempotency,
    ) {}

    public function handle(array $data, int $actorId): Payment
    {
        $context = app(TenantContext::class);
        $agencyId = $context->agencyId();
        if ($agencyId !== null && $agencyId !== (int) $data['agency_id']) {
            throw ValidationException::withMessages(['agency_id' => 'Cette agence ne fait pas partie du contexte actif.']);
        }

        $customer = Customer::findOrFail($data['customer_id']);
        $contract = isset($data['rental_contract_id']) ? RentalContract::findOrFail($data['rental_contract_id']) : null;
        foreach (['card_number', 'pan', 'cvv', 'cvc'] as $forbidden) {
            if (array_key_exists($forbidden, $data)) {
                throw ValidationException::withMessages([$forbidden => 'Les données de carte ne doivent jamais être stockées.']);
            }
        }

        $amount = DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['amount']));
        $currency = strtoupper($data['currency'] ?? 'MAD');

        return DB::transaction(function () use ($data, $actorId, $amount, $currency, $context, $contract, $customer) {
            $this->idempotency->lock($data['idempotency_key']);
            $existing = Payment::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                $this->idempotency->assertSameOperation($existing, [
                    'tenant_id' => $context->tenantId(),
                    'agency_id' => (int) $data['agency_id'],
                    'rental_contract_id' => isset($data['rental_contract_id']) ? (int) $data['rental_contract_id'] : null,
                    'customer_id' => (int) $data['customer_id'],
                    'direction' => $data['direction'] ?? 'incoming',
                    'payment_method' => $data['payment_method'],
                    'amount' => $amount,
                    'currency' => $currency,
                    'external_reference' => $data['external_reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'reversal_of_id' => null,
                ]);

                return $existing;
            }

            if (($customer->agency_id && $customer->agency_id !== (int) $data['agency_id']) || ($contract && ($contract->agency_id !== (int) $data['agency_id'] || $contract->customer_id !== $customer->id))) {
                throw ValidationException::withMessages(['payment' => 'Le client, le contrat et l’agence sont incompatibles.']);
            }

            if ($contract && trim($contract->currency) !== $currency) {
                throw ValidationException::withMessages(['currency' => 'La devise doit correspondre à celle du contrat.']);
            }

            $payment = Payment::create([
                'agency_id' => $data['agency_id'],
                'rental_contract_id' => $data['rental_contract_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'payment_number' => $this->numbers->handle('payment'),
                'direction' => $data['direction'] ?? 'incoming',
                'payment_method' => $data['payment_method'],
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'external_reference' => $data['external_reference'] ?? null,
                'idempotency_key' => $data['idempotency_key'],
                'paid_at' => $data['paid_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
            $this->audit->record('payment.recorded', $payment, [], ['payment_number' => $payment->payment_number, 'amount' => $payment->amount, 'method' => $payment->payment_method]);

            return $payment;
        });
    }
}
