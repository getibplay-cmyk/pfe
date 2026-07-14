<?php

namespace App\Models;

use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleBlock extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['agency_id', 'vehicle_id', 'reservation_id', 'rental_contract_id', 'block_type', 'starts_at', 'ends_at', 'status', 'reason', 'created_by', 'released_at'];

    protected function casts(): array
    {
        return [
            'block_type' => VehicleBlockType::class,
            'status' => VehicleBlockStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
