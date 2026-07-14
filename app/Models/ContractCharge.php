<?php

namespace App\Models;

use App\Enums\ContractChargeStatus;
use App\Enums\ContractChargeType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractCharge extends Model
{
    use BelongsToTenant;

    protected $fillable = ['rental_contract_id', 'damage_report_id', 'charge_type', 'description', 'quantity', 'unit_amount', 'total_amount', 'status', 'calculation_details', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['charge_type' => ContractChargeType::class, 'status' => ContractChargeStatus::class, 'quantity' => 'decimal:2', 'unit_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'calculation_details' => 'array', 'approved_at' => 'immutable_datetime'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function damageReport(): BelongsTo
    {
        return $this->belongsTo(DamageReport::class);
    }
}
