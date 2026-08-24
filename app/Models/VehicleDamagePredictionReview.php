<?php

namespace App\Models;

use App\Enums\VehicleDamageReviewDecision;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDamagePredictionReview extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'vehicle_damage_prediction_run_id',
        'reviewed_by',
        'decision',
        'note',
        'effect',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => VehicleDamageReviewDecision::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(
            VehicleDamagePredictionRun::class,
            'vehicle_damage_prediction_run_id',
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
