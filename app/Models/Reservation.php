<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = ['agency_id', 'customer_id', 'driver_id', 'vehicle_category_id', 'vehicle_id', 'reservation_number', 'starts_at', 'ends_at', 'status', 'pricing_rule_id', 'billed_days', 'daily_rate', 'subtotal', 'options_total', 'total_amount', 'deposit_amount', 'currency', 'pricing_snapshot', 'notes', 'expires_at', 'confirmed_at', 'cancelled_at', 'cancellation_reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'status' => ReservationStatus::class,
            'daily_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'options_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'pricing_snapshot' => 'array',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class);
    }

    public function vehicleBlocks(): HasMany
    {
        return $this->hasMany(VehicleBlock::class);
    }

    public function activeVehicleBlock(): HasOne
    {
        return $this->hasOne(VehicleBlock::class)->where('status', 'active');
    }

    public function rentalContract(): HasOne
    {
        return $this->hasOne(RentalContract::class);
    }
}
