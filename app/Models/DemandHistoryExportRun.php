<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandHistoryExportRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'run_id',
        'manifest_version',
        'schema_version',
        'dataset_version',
        'preprocessing_version',
        'target_semantics',
        'vehicle_category_scope',
        'timezone',
        'distance_unit',
        'agency_key',
        'series_key',
        'date_from',
        'date_to',
        'row_count',
        'max_rows',
        'observed_departures_count',
        'content_sha256',
        'byte_size',
        'format',
        'stored_path',
        'original_name',
        'operational_effect',
        'created_by',
        'created_at',
    ];

    protected $hidden = ['stored_path', 'created_by'];

    protected function casts(): array
    {
        return [
            'date_from' => 'immutable_date',
            'date_to' => 'immutable_date',
            'row_count' => 'integer',
            'max_rows' => 'integer',
            'observed_departures_count' => 'integer',
            'byte_size' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function forecastRuns(): HasMany
    {
        return $this->hasMany(DemandForecastRun::class);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'manifest_version' => $this->manifest_version,
            'run_id' => $this->run_id,
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'dataset' => [
                'schema_version' => $this->schema_version,
                'dataset_version' => $this->dataset_version,
                'preprocessing_version' => $this->preprocessing_version,
                'columns' => DemandForecastContract::snapshotHeaders(),
                'target' => $this->target_semantics,
                'vehicle_category' => $this->vehicle_category_scope,
                'missing_dates' => 'zero_filled',
            ],
            'scope' => [
                'kind' => 'agency',
                'agency_key' => $this->agency_key,
                'series_key' => $this->series_key,
            ],
            'period' => [
                'date_from' => $this->date_from->toDateString(),
                'date_to' => $this->date_to->toDateString(),
                'timezone' => $this->timezone,
                'cutoff_policy' => 'strictly_before_target',
            ],
            'snapshot' => [
                'format' => $this->format,
                'row_count' => $this->row_count,
                'max_rows' => $this->max_rows,
                'observed_departures_count' => $this->observed_departures_count,
                'byte_size' => $this->byte_size,
                'content_sha256' => $this->content_sha256,
            ],
            'units' => [
                'distance' => $this->distance_unit,
                'demand' => 'departures_per_local_day',
            ],
            'safety' => [
                'pseudonymized' => true,
                'contains_direct_identifiers' => false,
                'contains_predictions' => false,
                'contains_human_decisions' => false,
                'operational_effect' => $this->operational_effect,
            ],
        ];
    }
}
