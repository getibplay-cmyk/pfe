<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceOrder extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['agency_id', 'vehicle_id', 'maintenance_number', 'maintenance_type', 'priority', 'status', 'title', 'description', 'scheduled_start_at', 'scheduled_end_at', 'actual_start_at', 'actual_end_at', 'mileage_at_opening', 'estimated_cost', 'actual_cost', 'supplier', 'next_due_date', 'next_due_mileage', 'created_by', 'approved_by', 'completed_by'];

    protected function casts(): array
    {
        return ['scheduled_start_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime', 'actual_start_at' => 'immutable_datetime', 'actual_end_at' => 'immutable_datetime', 'next_due_date' => 'immutable_date', 'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MaintenanceStatusHistory::class);
    }

    public function vehicleBlock(): HasOne
    {
        return $this->hasOne(VehicleBlock::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
