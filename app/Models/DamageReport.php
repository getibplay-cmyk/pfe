<?php

namespace App\Models;

use App\Enums\DamageResponsibility;
use App\Enums\DamageSeverity;
use App\Enums\DamageStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DamageReport extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['agency_id', 'rental_contract_id', 'vehicle_id', 'departure_inspection_id', 'return_inspection_id', 'damage_number', 'description', 'vehicle_area', 'severity', 'status', 'responsibility', 'estimated_cost', 'approved_cost', 'reported_by', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['severity' => DamageSeverity::class, 'status' => DamageStatus::class, 'responsibility' => DamageResponsibility::class, 'estimated_cost' => 'decimal:2', 'approved_cost' => 'decimal:2', 'reviewed_at' => 'immutable_datetime'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function departureInspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'departure_inspection_id');
    }

    public function returnInspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'return_inspection_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(DamageStatusHistory::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(ContractCharge::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
