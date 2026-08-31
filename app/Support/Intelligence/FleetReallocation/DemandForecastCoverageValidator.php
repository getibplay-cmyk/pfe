<?php

namespace App\Support\Intelligence\FleetReallocation;

use App\Models\DemandForecastExecutionRun;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastPlanningUnits;
use Throwable;

class DemandForecastCoverageValidator
{
    public function __construct(private readonly DemandForecastPlanningUnits $planningUnits) {}

    public function compatible(DemandForecastExecutionRun $execution): bool
    {
        $run = $execution->forecastRun;
        if ($run === null
            || $run->agency_id !== $execution->agency_id
            || $run->validation_status !== 'validated'
            || $run->target_semantics !== DemandForecastContract::TARGET
            || $run->result_count !== 7
            || $run->as_of_date === null) {
            return false;
        }

        $rows = $run->forecasts->sortBy('horizon')->values();
        if ($rows->count() !== 7 || $rows->pluck('target_date')->unique()->count() !== 7) {
            return false;
        }

        foreach ($rows as $position => $forecast) {
            $horizon = $position + 1;
            if ($forecast->horizon !== $horizon
                || $forecast->target_date?->toDateString() !== $run->as_of_date->addDays($horizon)->toDateString()
                || $forecast->vehicle_category_scope !== DemandForecastContract::VEHICLE_CATEGORY_SCOPE
                || $forecast->demand_semantics !== DemandForecastContract::TARGET) {
                return false;
            }
            try {
                $this->planningUnits->convert((string) $forecast->conditional_mean);
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
