<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['agency_id', 'vehicle_id', 'rental_contract_id', 'maintenance_order_id', 'expense_number', 'category', 'description', 'amount', 'tax_amount', 'currency', 'expense_date', 'supplier', 'document_id', 'status', 'created_by', 'approved_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'expense_date' => 'immutable_date'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function maintenanceOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceOrder::class);
    }
}
