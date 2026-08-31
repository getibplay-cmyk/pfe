<?php

namespace App\Models;

use App\Enums\FleetReallocationPlanningRunStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetReallocationPlanningRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'requested_by',
        'source_kind',
        'status',
        'outcome',
        'solver_status',
        'failure_code',
        'reference_date',
        'input_fingerprint',
        'distance_matrix_fingerprint',
        'runtime_sha256',
        'snapshot',
        'runtime_result',
        'operational_effect',
        'requested_at',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'input_fingerprint',
        'distance_matrix_fingerprint',
        'runtime_sha256',
        'snapshot',
        'runtime_result',
    ];

    protected function casts(): array
    {
        return [
            'status' => FleetReallocationPlanningRunStatus::class,
            'reference_date' => 'immutable_date',
            'snapshot' => 'array',
            'runtime_result' => 'array',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(FleetReallocationRecommendation::class)
            ->orderBy('horizon')
            ->orderBy('from_agency_id')
            ->orderBy('to_agency_id');
    }
}
