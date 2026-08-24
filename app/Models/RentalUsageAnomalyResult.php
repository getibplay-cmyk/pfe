<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RentalUsageAnomalyResult extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'rental_usage_anomaly_run_id',
        'rental_contract_id',
        'row_id',
        'contract_key',
        'event_at',
        'late_hours',
        'km_per_day',
        'fuel_drop_pct',
        'primary_score',
        'primary_rank',
        'primary_selected_005',
        'primary_selected_010',
        'primary_selected_020',
        'primary_factors',
        'challenger_score',
        'challenger_rank',
        'challenger_selected_005',
        'challenger_selected_010',
        'challenger_selected_020',
        'operational_effect',
        'recorded_at',
    ];

    protected $hidden = ['row_id', 'contract_key'];

    protected function casts(): array
    {
        return [
            'event_at' => 'immutable_datetime',
            'late_hours' => 'decimal:6',
            'km_per_day' => 'decimal:6',
            'fuel_drop_pct' => 'decimal:6',
            'primary_score' => 'decimal:8',
            'primary_rank' => 'integer',
            'primary_selected_005' => 'boolean',
            'primary_selected_010' => 'boolean',
            'primary_selected_020' => 'boolean',
            'primary_factors' => 'array',
            'challenger_score' => 'decimal:8',
            'challenger_rank' => 'integer',
            'challenger_selected_005' => 'boolean',
            'challenger_selected_010' => 'boolean',
            'challenger_selected_020' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RentalUsageAnomalyRun::class, 'rental_usage_anomaly_run_id');
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RentalUsageAnomalyReview::class);
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(RentalUsageAnomalyReview::class)->latestOfMany();
    }

    public function selectedForBudget(int $basisPoints): bool
    {
        return match ($basisPoints) {
            50 => $this->primary_selected_005,
            100 => $this->primary_selected_010,
            200 => $this->primary_selected_020,
            default => false,
        };
    }

    public function challengerSelectedForBudget(int $basisPoints): bool
    {
        return match ($basisPoints) {
            50 => $this->challenger_selected_005,
            100 => $this->challenger_selected_010,
            200 => $this->challenger_selected_020,
            default => false,
        };
    }
}
