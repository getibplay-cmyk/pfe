<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    public function __construct(private GenerateBusinessNumber $numbers, private AuditRecorder $audit) {}

    public function handle(array $data, int $actorId): Payment
    {
        $agencyId = app(TenantContext::class)->agencyId();
        if ($agencyId !== null && $agencyId !== (int) $data['agency_id']) {
            throw ValidationException::withMessages(['agency_id' => 'Cette agence ne fait pas partie du contexte actif.']);
        }
        $customer = Customer::findOrFail($data['customer_id']);
        $contract = isset($data['rental_contract_id']) ? RentalContract::findOrFail($data['rental_contract_id']) : null;
        if (($customer->agency_id && $customer->agency_id !== (int) $data['agency_id']) || ($contract && ($contract->agency_id !== (int) $data['agency_id'] || $contract->customer_id !== $customer->id))) {
            throw ValidationException::withMessages(['payment' => 'Le client, le contrat et l’agence sont incompatibles.']);
        }
        foreach (['card_number', 'pan', 'cvv', 'cvc'] as $forbidden) {
            if (array_key_exists($forbidden, $data)) {
                throw ValidationException::withMessages([$forbidden => 'Les données de carte ne doivent jamais être stockées.']);
            }
        }
        DecimalMoney::toMinorUnits($data['amount']);

        return DB::transaction(function () use ($data, $actorId) {
            $existing = Payment::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->amount !== DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['amount'])) || $existing->customer_id !== (int) $data['customer_id']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Cette clé est déjà associée à une autre opération.']);
                }

                return $existing;
            }
            $payment = Payment::create([
                'agency_id' => $data['agency_id'], 'rental_contract_id' => $data['rental_contract_id'] ?? null,
                'customer_id' => $data['customer_id'], 'payment_number' => $this->numbers->handle('payment'),
                'direction' => $data['direction'] ?? 'incoming', 'payment_method' => $data['payment_method'], 'status' => 'pending',
                'amount' => DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['amount'])), 'currency' => $data['currency'] ?? 'MAD',
                'external_reference' => $data['external_reference'] ?? null, 'idempotency_key' => $data['idempotency_key'],
                'paid_at' => $data['paid_at'] ?? now(), 'notes' => $data['notes'] ?? null, 'created_by' => $actorId,
            ]);
            $this->audit->record('payment.recorded', $payment, [], ['payment_number' => $payment->payment_number, 'amount' => $payment->amount, 'method' => $payment->payment_method]);

            return $payment;
        });
    }
}
