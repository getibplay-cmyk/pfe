<?php

namespace App\Support\Intelligence;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\RentalContractStatus;
use App\Models\RentalContract;
use App\Support\Reporting\ReportCriteria;
use Illuminate\Database\Eloquent\Builder;

final class RentalAnomalyDataset
{
    /**
     * @return Builder<RentalContract>
     */
    public function query(ReportCriteria $criteria): Builder
    {
        return RentalContract::query()
            ->where('tenant_id', $criteria->tenantId)
            ->whereIn('agency_id', $criteria->agencyIds)
            ->whereIn('status', [RentalContractStatus::Returned->value, RentalContractStatus::Closed->value])
            ->where('actual_return_at', '>=', $criteria->startsAt)
            ->where('actual_return_at', '<', $criteria->endsAt)
            ->whereNotNull([
                'actual_start_at',
                'actual_return_at',
                'expected_return_at',
                'start_mileage',
                'return_mileage',
                'start_fuel_level',
                'return_fuel_level',
            ])
            ->whereColumn('actual_return_at', '>', 'actual_start_at')
            ->whereColumn('return_mileage', '>=', 'start_mileage')
            ->whereHas('inspections', fn (Builder $query) => $query
                ->where('inspection_type', InspectionType::Return->value)
                ->where('status', InspectionStatus::Completed->value));
    }
}
