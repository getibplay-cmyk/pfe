<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandForecast extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'demand_forecast_run_id',
        'row_position',
        'target_date',
        'horizon',
        'vehicle_category_scope',
        'conditional_mean',
        'p05',
        'p50',
        'p90',
        'p95',
        'raw_any_crossing',
        'monotone_adjusted',
        'explanations',
        'demand_semantics',
        'operational_effect',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'row_position' => 'integer',
            'target_date' => 'immutable_date',
            'horizon' => 'integer',
            'conditional_mean' => 'decimal:6',
            'p05' => 'decimal:6',
            'p50' => 'decimal:6',
            'p90' => 'decimal:6',
            'p95' => 'decimal:6',
            'raw_any_crossing' => 'boolean',
            'monotone_adjusted' => 'boolean',
            'explanations' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DemandForecastRun::class, 'demand_forecast_run_id');
    }
}
