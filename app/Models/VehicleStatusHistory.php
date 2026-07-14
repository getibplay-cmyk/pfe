<?php

namespace App\Models;

use App\Enums\VehicleOperationalStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleStatusHistory extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_status' => VehicleOperationalStatus::class, 'to_status' => VehicleOperationalStatus::class, 'created_at' => 'immutable_datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
