<?php

namespace App\Actions\Reservations;

use App\Enums\VehicleOperationalStatus;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class SearchAvailableVehicles
{
    public function query(int $agencyId, CarbonInterface $startsAt, CarbonInterface $endsAt, ?int $categoryId = null): Builder
    {
        return Vehicle::query()
            ->where('agency_id', $agencyId)
            ->where('operational_status', VehicleOperationalStatus::Active)
            ->when($categoryId, fn (Builder $query) => $query->where('vehicle_category_id', $categoryId))
            ->whereDoesntHave('blocks', fn (Builder $query) => $query
                ->where('status', 'active')
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt));
    }
}
