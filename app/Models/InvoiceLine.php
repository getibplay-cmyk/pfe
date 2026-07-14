<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['invoice_id', 'source_type', 'source_id', 'line_type', 'description', 'quantity', 'unit_amount', 'subtotal', 'tax_rate', 'tax_amount', 'total_amount', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_amount' => 'decimal:2', 'subtotal' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
