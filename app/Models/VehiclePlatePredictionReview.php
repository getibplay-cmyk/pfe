<?php

namespace App\Models;

use App\Enums\VehiclePlateReviewDecision;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehiclePlatePredictionReview extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'vehicle_plate_prediction_run_id',
        'reviewed_by',
        'decision',
        'verified_canonical',
        'note',
        'effect',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => VehiclePlateReviewDecision::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(
            VehiclePlatePredictionRun::class,
            'vehicle_plate_prediction_run_id',
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
