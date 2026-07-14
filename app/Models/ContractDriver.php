<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractDriver extends Model
{
    use BelongsToTenant;

    protected $fillable = ['rental_contract_id', 'customer_id', 'driver_id', 'is_primary', 'authorization_snapshot'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'authorization_snapshot' => 'array'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
