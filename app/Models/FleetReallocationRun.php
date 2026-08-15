<?php

namespace App\Models;

use App\Enums\FleetReallocationRunStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetReallocationRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'requested_by',
        'fleet_reallocation_proposal_id',
        'forecast_horizon',
        'scenario_number',
        'status',
        'failure_code',
        'operational_effect',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'forecast_horizon' => 'integer',
            'scenario_number' => 'integer',
            'status' => FleetReallocationRunStatus::class,
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(FleetReallocationProposal::class, 'fleet_reallocation_proposal_id');
    }
}
