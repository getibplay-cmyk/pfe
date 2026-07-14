<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['agency_id', 'rental_contract_id', 'customer_id', 'payment_number', 'direction', 'payment_method', 'status', 'amount', 'currency', 'external_reference', 'idempotency_key', 'paid_at', 'posted_at', 'reversal_of_id', 'notes', 'created_by', 'posted_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
