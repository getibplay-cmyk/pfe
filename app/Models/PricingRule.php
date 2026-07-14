<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PricingRule extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = ['agency_id', 'vehicle_category_id', 'name', 'daily_rate', 'deposit_amount', 'included_km_per_day', 'extra_km_rate', 'late_hour_rate', 'minimum_days', 'maximum_days', 'valid_from', 'valid_to', 'priority', 'currency', 'conditions', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'extra_km_rate' => 'decimal:2',
            'late_hour_rate' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
