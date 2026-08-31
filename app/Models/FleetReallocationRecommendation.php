<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetReallocationRecommendation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'fleet_reallocation_planning_run_id',
        'horizon',
        'planning_date',
        'from_agency_id',
        'to_agency_id',
        'vehicle_units',
        'distance_km',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'horizon' => 'integer',
            'planning_date' => 'immutable_date',
            'vehicle_units' => 'integer',
            'distance_km' => 'decimal:3',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FleetReallocationPlanningRun::class, 'fleet_reallocation_planning_run_id');
    }

    public function originAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'from_agency_id');
    }

    public function destinationAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'to_agency_id');
    }
}
