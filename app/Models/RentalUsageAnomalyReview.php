<?php

namespace App\Models;

use App\Enums\RentalUsageAnomalyReviewDecision;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalUsageAnomalyReview extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'rental_usage_anomaly_result_id',
        'reviewed_by',
        'decision',
        'note',
        'effect',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => RentalUsageAnomalyReviewDecision::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(RentalUsageAnomalyResult::class, 'rental_usage_anomaly_result_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
