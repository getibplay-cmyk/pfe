<?php

namespace App\Models;

use App\Enums\VehicleOperationalStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = ['agency_id', 'vehicle_category_id', 'registration_number', 'vin', 'brand', 'model', 'production_year', 'fuel_type', 'transmission', 'color', 'current_mileage', 'first_registration_date'];

    protected function casts(): array
    {
        return ['operational_status' => VehicleOperationalStatus::class, 'first_registration_date' => 'date', 'custom_values' => 'array'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(VehicleStatusHistory::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(VehicleBlock::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function rentalContracts(): HasMany
    {
        return $this->hasMany(RentalContract::class);
    }

    public function maintenanceOrders(): HasMany
    {
        return $this->hasMany(MaintenanceOrder::class);
    }

    public function insurancePolicies(): HasMany
    {
        return $this->hasMany(InsurancePolicy::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
