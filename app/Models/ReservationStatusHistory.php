<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationStatusHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['reservation_id', 'from_status', 'to_status', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return ['from_status' => ReservationStatus::class, 'to_status' => ReservationStatus::class, 'created_at' => 'immutable_datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
