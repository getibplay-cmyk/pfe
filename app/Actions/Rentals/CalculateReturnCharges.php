<?php

namespace App\Actions\Rentals;

use App\Enums\ContractChargeStatus;
use App\Enums\ContractChargeType;
use App\Enums\RentalContractStatus;
use App\Models\ContractCharge;
use App\Models\RentalContract;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CalculateReturnCharges
{
    public function handle(RentalContract $contract, array $manual = []): array
    {
        return DB::transaction(function () use ($contract, $manual) {
            $locked = RentalContract::with('currentVersion')->whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::ReturnPending) {
                throw ValidationException::withMessages(['status' => 'Les frais retour exigent un contrat en attente de retour.']);
            }
            $locked->charges()->where('status', 'proposed')->whereIn('charge_type', ['late_fee', 'extra_kilometre', 'missing_fuel', 'cleaning'])->delete();
            $terms = $locked->currentVersion->terms_snapshot;
            $details = [];
            $actualReturnAt = $manual['actual_return_at'] ?? $locked->inspections()->where('inspection_type', 'return')->where('status', 'completed')->value('inspected_at') ?? now();
            $lateSeconds = max(0, $locked->expected_return_at->diffInSeconds($actualReturnAt, false));
            $lateHours = (int) ceil($lateSeconds / 3600);
            if ($lateHours > 0 && ! empty($terms['late_hour_rate'])) {
                $details[] = $this->charge($locked, ContractChargeType::LateFee, 'Heures de retard', (string) $lateHours, $terms['late_hour_rate'], ['late_seconds' => $lateSeconds, 'rounding' => 'ceil_hour']);
            }
            $travelled = max(0, (int) $locked->return_mileage - (int) $locked->start_mileage);
            $included = (int) ($terms['included_km_per_day'] ?? 0) * (int) ($locked->reservation->billed_days ?? 1);
            $extra = max(0, $travelled - $included);
            if ($extra > 0 && ! empty($terms['extra_km_rate'])) {
                $details[] = $this->charge($locked, ContractChargeType::ExtraKilometre, 'Kilomètres supplémentaires', (string) $extra, $terms['extra_km_rate'], ['travelled_km' => $travelled, 'included_km' => $included]);
            }
            $missingFuel = max(0, $this->hundredths($locked->start_fuel_level) - $this->hundredths($locked->return_fuel_level));
            if ($missingFuel > 0) {
                $details[] = $this->charge($locked, ContractChargeType::MissingFuel, 'Carburant manquant', $this->fromHundredths($missingFuel), $terms['fuel_policy']['missing_unit_rate'] ?? config('rentals.missing_fuel_unit_rate'), ['start_fuel' => $locked->start_fuel_level, 'return_fuel' => $locked->return_fuel_level]);
            }
            if (($manual['cleaning_approved'] ?? false) && DecimalMoney::toMinorUnits($manual['cleaning_amount'] ?? '0.00') > 0) {
                $details[] = $this->charge($locked, ContractChargeType::Cleaning, 'Nettoyage approuvé manuellement', '1.00', $manual['cleaning_amount'], ['manual_approval' => true]);
            }

            return ['late_hours' => $lateHours, 'travelled_km' => $travelled, 'included_km' => $included, 'extra_km' => $extra, 'missing_fuel' => $this->fromHundredths($missingFuel), 'charges' => $details];
        });
    }

    private function charge(RentalContract $contract, ContractChargeType $type, string $description, string $quantity, string $unit, array $details): ContractCharge
    {
        $totalCents = intdiv(($this->hundredths($quantity) * DecimalMoney::toMinorUnits($unit)) + 50, 100);

        return ContractCharge::create(['rental_contract_id' => $contract->id, 'charge_type' => $type, 'description' => $description, 'quantity' => $quantity, 'unit_amount' => $unit, 'total_amount' => DecimalMoney::fromMinorUnits($totalCents), 'status' => ContractChargeStatus::Proposed, 'calculation_details' => $details]);
    }

    private function hundredths(string|int|null $value): int
    {
        $normalized = trim((string) ($value ?? 0));
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalized, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Valeur décimale invalide.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function fromHundredths(int $value): string
    {
        return sprintf('%d.%02d', intdiv($value, 100), $value % 100);
    }
}
