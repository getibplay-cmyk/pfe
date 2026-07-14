<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositTransaction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['agency_id', 'rental_contract_id', 'transaction_number', 'transaction_type', 'amount', 'currency', 'payment_id', 'related_charge_id', 'reversal_of_id', 'idempotency_key', 'occurred_at', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'immutable_datetime'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
