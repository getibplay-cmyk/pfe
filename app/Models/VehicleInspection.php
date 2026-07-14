<?php

namespace App\Models;

use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class VehicleInspection extends Model
{
    use BelongsToTenant;

    protected $fillable = ['agency_id', 'rental_contract_id', 'vehicle_id', 'inspection_type', 'status', 'inspected_at', 'mileage', 'fuel_level', 'notes', 'completed_by', 'completed_at', 'created_by'];

    protected function casts(): array
    {
        return ['inspection_type' => InspectionType::class, 'status' => InspectionStatus::class, 'inspected_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'fuel_level' => 'decimal:2'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionItem::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
