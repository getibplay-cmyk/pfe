<?php

namespace App\Support\Intelligence\FleetReallocation;

use App\Support\Fleet\AgencyDistanceMatrixResult;

final readonly class FleetReallocationReadinessResult
{
    /**
     * @param  list<string>  $issues
     * @param  list<int>  $missingForecastAgencyIds
     * @param  list<int>  $incompatibleForecastAgencyIds
     * @param  array<int, array<int, int>>  $availabilityByAgencyAndHorizon
     */
    public function __construct(
        public string $status,
        public array $issues,
        public AgencyDistanceMatrixResult $matrix,
        public array $missingForecastAgencyIds,
        public array $incompatibleForecastAgencyIds,
        public array $availabilityByAgencyAndHorizon,
    ) {}

    public function ready(): bool
    {
        return $this->status === 'ready';
    }
}
