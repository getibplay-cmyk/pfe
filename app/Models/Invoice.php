<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['agency_id', 'rental_contract_id', 'customer_id', 'invoice_number', 'status', 'issued_at', 'due_at', 'currency', 'tax_mode', 'tax_rate', 'subtotal', 'tax_amount', 'total_amount', 'paid_amount', 'balance_due', 'customer_snapshot', 'contract_snapshot', 'content_hash', 'created_by', 'issued_by'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'tax_rate' => 'decimal:4', 'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'balance_due' => 'decimal:2', 'customer_snapshot' => 'array', 'contract_snapshot' => 'array'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
