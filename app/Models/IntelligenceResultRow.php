<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceResultRow extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'intelligence_result_batch_id',
        'row_position',
        'row_key',
        'advisory_kind',
        'priority',
        'summary_code',
        'factors',
        'operational_effect',
        'created_at',
    ];

    protected $hidden = ['row_key'];

    protected function casts(): array
    {
        return [
            'row_position' => 'integer',
            'factors' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IntelligenceResultBatch::class, 'intelligence_result_batch_id');
    }
}
