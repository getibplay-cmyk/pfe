<?php

namespace App\Models;

use App\Enums\J11DemoDecision;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiHumanDecisionDemo extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'ai_human_decisions_demo';

    protected $fillable = [
        'agency_id',
        'ai_advisory_record_demo_id',
        'actor_user_id',
        'decision',
        'reason_code',
        'note',
        'effect',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => J11DemoDecision::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function advisory(): BelongsTo
    {
        return $this->belongsTo(AiAdvisoryRecordDemo::class, 'ai_advisory_record_demo_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
