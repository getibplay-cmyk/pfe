<?php

namespace App\Models;

use App\Enums\RentalContractStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractStatusHistory extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['rental_contract_id', 'from_status', 'to_status', 'reason', 'changed_by'];

    protected function casts(): array
    {
        return ['from_status' => RentalContractStatus::class, 'to_status' => RentalContractStatus::class, 'created_at' => 'immutable_datetime'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }
}
