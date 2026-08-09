<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiIdempotencyKeyDemo extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'ai_idempotency_keys_demo';

    protected $fillable = [
        'ai_advisory_record_demo_id',
        'idempotency_key',
        'fingerprint',
        'first_result',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function advisory(): BelongsTo
    {
        return $this->belongsTo(AiAdvisoryRecordDemo::class, 'ai_advisory_record_demo_id');
    }
}
