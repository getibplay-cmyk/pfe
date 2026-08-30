<?php

namespace App\Support\Intelligence\FleetReallocation;

use App\Actions\Reservations\SearchAvailableVehicles;
use App\Enums\DemandForecastExecutionStatus;
use App\Models\Agency;
use App\Models\DemandForecastExecutionRun;
use App\Support\Fleet\AgencyDistanceMatrixBuilder;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class FleetReallocationReadiness
{
    public function __construct(
        private readonly AgencyDistanceMatrixBuilder $matrixBuilder,
        private readonly DemandForecastCoverageValidator $forecastCoverage,
        private readonly SearchAvailableVehicles $availability,
        private readonly FleetReallocationRuntimeReadiness $runtime,
    ) {}

    /** @param Collection<int, Agency> $agencies */
    public function evaluate(Collection $agencies): FleetReallocationReadinessResult
    {
        $agencies = $agencies->sortBy('id')->values();
        $issues = [];
        if ($agencies->count() < 2) {
            $issues[] = 'missing_agencies';
        }

        $matrix = $this->matrixBuilder->build($agencies);
        if (! $matrix->complete()) {
            $issues[] = 'missing_distances';
        }

        $missingForecasts = [];
        $incompatibleForecasts = [];
        $asOfDates = [];
        foreach ($agencies as $agency) {
            $execution = DemandForecastExecutionRun::query()
                ->with('forecastRun.forecasts')
                ->where('agency_id', $agency->getKey())
                ->where('status', DemandForecastExecutionStatus::Succeeded->value)
                ->latest('finished_at')
                ->latest('id')
                ->first();
            if ($execution === null || $execution->forecastRun === null) {
                $missingForecasts[] = (int) $agency->getKey();

                continue;
            }
            if (! $this->forecastCoverage->compatible($execution)) {
                $incompatibleForecasts[] = (int) $agency->getKey();

                continue;
            }
            $asOfDates[(string) $execution->forecastRun->as_of_date?->toDateString()] = true;
        }
        if ($missingForecasts !== []) {
            $issues[] = 'missing_forecasts';
        }
        if ($incompatibleForecasts !== [] || count($asOfDates) > 1) {
            $issues[] = 'incompatible_forecasts';
        }

        $availability = $this->availability($agencies, array_key_first($asOfDates));
        if ($availability === null && ! in_array('missing_agencies', $issues, true)) {
            $issues[] = 'missing_agencies';
        }

        if (! $this->runtime->ready()) {
            $issues[] = 'runtime_unavailable';
        }

        $issues = array_values(array_unique($issues));

        return new FleetReallocationReadinessResult(
            status: $issues[0] ?? 'ready',
            issues: $issues,
            matrix: $matrix,
            missingForecastAgencyIds: $missingForecasts,
            incompatibleForecastAgencyIds: $incompatibleForecasts,
            availabilityByAgencyAndHorizon: $availability ?? [],
        );
    }

    /**
     * @param  Collection<int, Agency>  $agencies
     * @return array<int, array<int, int>>|null
     */
    private function availability(Collection $agencies, ?string $asOfDate): ?array
    {
        try {
            $asOf = $asOfDate !== null
                ? CarbonImmutable::parse($asOfDate, DemandForecastContract::TIMEZONE)->startOfDay()
                : CarbonImmutable::now(DemandForecastContract::TIMEZONE)->startOfDay();
            $counts = [];
            foreach ($agencies as $agency) {
                foreach (range(1, 7) as $horizon) {
                    $startsAt = $asOf->addDays($horizon);
                    $counts[$agency->getKey()][$horizon] = $this->availability
                        ->query($agency->getKey(), $startsAt, $startsAt->addDay())
                        ->count();
                }
            }

            return $counts;
        } catch (Throwable) {
            return null;
        }
    }
}
