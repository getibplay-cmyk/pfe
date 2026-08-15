<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandForecastRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'demand_history_export_run_id',
        'run_id',
        'idempotency_key',
        'schema_version',
        'model_name',
        'model_version',
        'model_artifact_sha256',
        'framework',
        'framework_version',
        'compute',
        'explanation_method',
        'mode',
        'validation_scope',
        'target_semantics',
        'generated_at',
        'as_of_date',
        'input_row_count',
        'input_content_sha256',
        'result_count',
        'public_wape',
        'public_mase',
        'public_interval_coverage',
        'local_holdout_status',
        'local_wape',
        'local_mase',
        'local_interval_coverage',
        'canonical_payload_sha256',
        'content_sha256',
        'byte_size',
        'stored_path',
        'original_name',
        'validation_status',
        'operational_effect',
        'imported_by',
        'imported_at',
    ];

    protected $hidden = ['stored_path', 'imported_by', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'generated_at' => 'immutable_datetime',
            'as_of_date' => 'immutable_date',
            'input_row_count' => 'integer',
            'result_count' => 'integer',
            'public_wape' => 'decimal:6',
            'public_mase' => 'decimal:6',
            'public_interval_coverage' => 'decimal:6',
            'local_wape' => 'decimal:6',
            'local_mase' => 'decimal:6',
            'local_interval_coverage' => 'decimal:6',
            'byte_size' => 'integer',
            'imported_at' => 'immutable_datetime',
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

    public function historyExport(): BelongsTo
    {
        return $this->belongsTo(DemandHistoryExportRun::class, 'demand_history_export_run_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(DemandForecast::class)->orderBy('horizon');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function publicWapeComplement(): string
    {
        return number_format((1 - (float) $this->public_wape) * 100, 2, ',', ' ');
    }
}
