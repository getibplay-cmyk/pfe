<?php

namespace App\Support\Intelligence;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\RentalContractStatus;
use App\Models\RentalContract;
use Carbon\CarbonImmutable;

final class BuildRentalAnomalyInput
{
    public function __construct(private readonly IntelligencePseudonymizer $pseudonymizer) {}

    public function handle(RentalContract $contract): ?PredictionInput
    {
        if (! in_array($contract->status, [RentalContractStatus::Returned, RentalContractStatus::Closed], true)
            || $contract->deleted_at !== null
            || $contract->actual_start_at === null
            || $contract->actual_return_at === null
            || $contract->expected_return_at === null
            || $contract->start_mileage === null
            || $contract->return_mileage === null
            || $contract->start_fuel_level === null
            || $contract->return_fuel_level === null) {
            return null;
        }

        $actualStart = CarbonImmutable::instance($contract->actual_start_at);
        $actualReturn = CarbonImmutable::instance($contract->actual_return_at);
        $expectedReturn = CarbonImmutable::instance($contract->expected_return_at);
        $durationSeconds = $actualReturn->getTimestamp() - $actualStart->getTimestamp();
        $distanceKm = (int) $contract->return_mileage - (int) $contract->start_mileage;

        if ($durationSeconds <= 0 || $distanceKm < 0 || ! $this->hasCompletedReturnInspection($contract)) {
            return null;
        }

        $lateSeconds = max(0, $actualReturn->getTimestamp() - $expectedReturn->getTimestamp());
        if ($distanceKm > intdiv(PHP_INT_MAX - intdiv($durationSeconds, 2), 86400)) {
            return null;
        }

        $startFuelHundredths = $this->decimalHundredths((string) $contract->start_fuel_level);
        $returnFuelHundredths = $this->decimalHundredths((string) $contract->return_fuel_level);
        if ($startFuelHundredths === null || $returnFuelHundredths === null) {
            return null;
        }

        $fuelDropHundredths = max(0, $startFuelHundredths - $returnFuelHundredths);
        $eventAt = $actualReturn->utc()->format('Y-m-d\TH:i:s\Z');
        $tenantId = (int) $contract->tenant_id;
        $agencyId = (int) $contract->agency_id;
        $contractId = (int) $contract->getKey();

        return new PredictionInput(
            schemaVersion: PredictionInput::SCHEMA_VERSION,
            datasetVersion: PredictionInput::DATASET_VERSION,
            rowId: $this->pseudonymizer->rowId($tenantId, $contractId, $eventAt),
            tenantKey: $this->pseudonymizer->tenantKey($tenantId),
            agencyKey: $this->pseudonymizer->agencyKey($tenantId, $agencyId),
            contractKey: $this->pseudonymizer->contractKey($tenantId, $contractId),
            eventAt: $eventAt,
            lateHours: $this->roundedRatio($lateSeconds, 3600),
            kmPerDay: $this->roundedRatio($distanceKm * 86400, $durationSeconds),
            fuelDropPct: $this->formatScaled($fuelDropHundredths * 10000),
        );
    }

    private function hasCompletedReturnInspection(RentalContract $contract): bool
    {
        if ($contract->relationLoaded('inspections')) {
            return $contract->inspections->contains(
                fn ($inspection): bool => $inspection->inspection_type === InspectionType::Return
                    && $inspection->status === InspectionStatus::Completed
            );
        }

        return $contract->inspections()
            ->where('inspection_type', InspectionType::Return->value)
            ->where('status', InspectionStatus::Completed->value)
            ->exists();
    }

    private function roundedRatio(int $numerator, int $denominator): string
    {
        $scaled = intdiv(($numerator * 1000000) + intdiv($denominator, 2), $denominator);

        return $this->formatScaled($scaled);
    }

    private function formatScaled(int $scaled): string
    {
        return intdiv($scaled, 1000000).'.'.str_pad((string) ($scaled % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decimalHundredths(string $value): ?int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
