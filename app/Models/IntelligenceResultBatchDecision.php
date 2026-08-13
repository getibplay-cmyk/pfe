<?php

namespace App\Models;

use App\Enums\IntelligenceResultBatchDecision as Decision;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceResultBatchDecision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'intelligence_result_batch_id',
        'actor_user_id',
        'decision',
        'reason_code',
        'effect',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => Decision::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IntelligenceResultBatch::class, 'intelligence_result_batch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
