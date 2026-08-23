<?php

namespace App\Models;

use App\Enums\VehicleColorReviewDecision;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleColorPredictionReview extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'vehicle_color_prediction_run_id',
        'reviewed_by',
        'decision',
        'note',
        'effect',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => VehicleColorReviewDecision::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(
            VehicleColorPredictionRun::class,
            'vehicle_color_prediction_run_id',
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
