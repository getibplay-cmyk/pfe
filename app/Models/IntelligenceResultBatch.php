<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntelligenceResultBatch extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'intelligence_dataset_export_run_id',
        'batch_id',
        'idempotency_key',
        'schema_version',
        'dataset_schema_version',
        'dataset_version',
        'export_row_count',
        'export_content_sha256',
        'source_kind',
        'computation_status',
        'producer_name',
        'producer_version',
        'producer_environment',
        'generated_at',
        'result_count',
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

    protected $hidden = [
        'idempotency_key',
        'export_content_sha256',
        'canonical_payload_sha256',
        'content_sha256',
        'stored_path',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'export_row_count' => 'integer',
            'result_count' => 'integer',
            'byte_size' => 'integer',
            'generated_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'batch_id';
    }

    public function exportRun(): BelongsTo
    {
        return $this->belongsTo(
            IntelligenceDatasetExportRun::class,
            'intelligence_dataset_export_run_id',
        );
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(IntelligenceResultRow::class);
    }

    public function decision(): HasOne
    {
        return $this->hasOne(IntelligenceResultBatchDecision::class);
    }

    public function reviewStatus(): string
    {
        return $this->decision?->decision->value ?? 'pending';
    }
}
