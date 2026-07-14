<?php

namespace App\Actions\Finance;

use App\Actions\Rentals\GenerateBusinessNumber;
use App\Enums\ContractChargeStatus;
use App\Enums\RentalContractStatus;
use App\Models\Invoice;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInvoiceFromReturnedContract
{
    public function __construct(private GenerateBusinessNumber $numbers, private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, int $actorId, string $taxMode = 'none', string $taxRate = '0.0000'): Invoice
    {
        return DB::transaction(function () use ($contract, $actorId, $taxMode, $taxRate) {
            $locked = RentalContract::with(['customer', 'charges'])->whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::Returned) {
                throw ValidationException::withMessages(['status' => 'La facture finale exige un contrat retourné.']);
            }
            if ($locked->charges->contains(fn ($charge) => $charge->status === ContractChargeStatus::Proposed)) {
                throw ValidationException::withMessages(['charges' => 'Tous les frais doivent être revus avant facturation.']);
            }
            if (! in_array($taxMode, ['none', 'inclusive', 'exclusive'], true)) {
                throw ValidationException::withMessages(['tax_mode' => 'Mode de taxe invalide.']);
            }
            if ($taxMode === 'none') {
                $taxRate = '0.0000';
            }

            $sources = collect([[
                'source_type' => 'contract', 'source_id' => $locked->id, 'line_type' => 'rental',
                'description' => 'Location '.$locked->contract_number, 'quantity' => '1.00', 'amount' => $locked->rental_subtotal,
            ]])->merge($locked->charges->where('status', ContractChargeStatus::Approved)->values()->map(fn ($charge) => [
                'source_type' => 'contract_charge', 'source_id' => $charge->id,
                'line_type' => match ($charge->charge_type->value) {
                    'late_fee' => 'late_fee', 'extra_kilometre' => 'extra_kilometre', 'missing_fuel' => 'fuel',
                    'cleaning' => 'cleaning', 'damage' => 'damage', default => 'other',
                },
                'description' => $charge->description, 'quantity' => '1.00', 'amount' => $charge->total_amount,
            ]));

            $lines = $sources->map(function (array $source, int $index) use ($taxMode, $taxRate) {
                $grossMinor = DecimalMoney::toMinorUnits($source['amount']);
                $tax = match ($taxMode) {
                    'exclusive' => DecimalMoney::taxForExclusive($source['amount'], $taxRate),
                    'inclusive' => DecimalMoney::taxForInclusive($source['amount'], $taxRate),
                    default => '0.00',
                };
                $taxMinor = DecimalMoney::toMinorUnits($tax);
                $subtotalMinor = $taxMode === 'inclusive' ? $grossMinor - $taxMinor : $grossMinor;
                $totalMinor = $taxMode === 'exclusive' ? $grossMinor + $taxMinor : $grossMinor;

                return [...$source, 'unit_amount' => DecimalMoney::fromMinorUnits($grossMinor), 'subtotal' => DecimalMoney::fromMinorUnits($subtotalMinor), 'tax_rate' => $taxRate, 'tax_amount' => $tax, 'total_amount' => DecimalMoney::fromMinorUnits($totalMinor), 'sort_order' => $index + 1];
            });
            $subtotal = $lines->sum(fn ($line) => DecimalMoney::toMinorUnits($line['subtotal']));
            $tax = $lines->sum(fn ($line) => DecimalMoney::toMinorUnits($line['tax_amount']));
            $total = $lines->sum(fn ($line) => DecimalMoney::toMinorUnits($line['total_amount']));
            $customerSnapshot = ['id' => $locked->customer_id, 'name' => $locked->customer->displayName(), 'city' => $locked->customer->city];
            $contractSnapshot = ['id' => $locked->id, 'number' => $locked->contract_number, 'returned_at' => $locked->returned_at?->toIso8601String(), 'currency' => $locked->currency];
            $hashPayload = ['customer' => $customerSnapshot, 'contract' => $contractSnapshot, 'tax_mode' => $taxMode, 'tax_rate' => $taxRate, 'lines' => $lines->values()->all(), 'total' => DecimalMoney::fromMinorUnits($total)];

            $invoice = Invoice::create([
                'agency_id' => $locked->agency_id, 'rental_contract_id' => $locked->id, 'customer_id' => $locked->customer_id,
                'invoice_number' => $this->numbers->handle('invoice'), 'status' => 'draft', 'currency' => $locked->currency,
                'tax_mode' => $taxMode, 'tax_rate' => $taxRate, 'subtotal' => DecimalMoney::fromMinorUnits($subtotal),
                'tax_amount' => DecimalMoney::fromMinorUnits($tax), 'total_amount' => DecimalMoney::fromMinorUnits($total),
                'paid_amount' => '0.00', 'balance_due' => DecimalMoney::fromMinorUnits($total),
                'customer_snapshot' => $customerSnapshot, 'contract_snapshot' => $contractSnapshot,
                'content_hash' => hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'created_by' => $actorId,
            ]);
            foreach ($lines as $line) {
                unset($line['amount']);
                $invoice->lines()->create($line);
            }
            $locked->forceFill(['invoice_id' => $invoice->id, 'amount_paid' => '0.00', 'balance_due' => $invoice->balance_due])->save();
            $this->audit->record('invoice.created', $invoice, [], ['invoice_number' => $invoice->invoice_number, 'total_amount' => $invoice->total_amount]);

            return $invoice->load('lines');
        });
    }
}
