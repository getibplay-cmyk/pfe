<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntelligenceDatasetExportRun extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'run_id',
        'manifest_version',
        'schema_version',
        'dataset_version',
        'scope_kind',
        'scope_key',
        'date_from',
        'date_to',
        'timezone',
        'row_count',
        'max_rows',
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

    public function resultBatches(): HasMany
    {
        return $this->hasMany(IntelligenceResultBatch::class);
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
            ],
            'scope' => [
                'kind' => $this->scope_kind,
                'key' => $this->scope_key,
            ],
            'period' => [
                'date_from' => $this->date_from->toDateString(),
                'date_to' => $this->date_to->toDateString(),
                'timezone' => $this->timezone,
                'interval' => '[date_from, date_to + 1 day)',
            ],
            'snapshot' => [
                'format' => $this->format,
                'row_count' => $this->row_count,
                'max_rows' => $this->max_rows,
                'byte_size' => $this->byte_size,
                'content_sha256' => $this->content_sha256,
            ],
            'safety' => [
                'pseudonymized' => true,
                'contains_predictions' => false,
                'contains_labels' => false,
                'contains_human_decisions' => false,
                'operational_effect' => $this->operational_effect,
            ],
        ];
    }
}
