<?php

namespace App\Models;

use App\Enums\RentalContractStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentalContract extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['agency_id', 'reservation_id', 'customer_id', 'vehicle_id', 'contract_number', 'status', 'current_version_id', 'expected_start_at', 'expected_return_at', 'actual_start_at', 'actual_return_at', 'start_mileage', 'return_mileage', 'start_fuel_level', 'return_fuel_level', 'rental_subtotal', 'additional_charges_total', 'total_amount', 'deposit_required', 'currency', 'accepted_at', 'activated_at', 'returned_at', 'closed_at', 'cancelled_at', 'created_by', 'invoice_id', 'amount_paid', 'balance_due', 'deposit_received', 'deposit_retained', 'deposit_refunded', 'financially_settled_at', 'closed_by'];

    protected function casts(): array
    {
        return ['status' => RentalContractStatus::class, 'expected_start_at' => 'immutable_datetime', 'expected_return_at' => 'immutable_datetime', 'actual_start_at' => 'immutable_datetime', 'actual_return_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'financially_settled_at' => 'immutable_datetime', 'start_fuel_level' => 'decimal:2', 'return_fuel_level' => 'decimal:2', 'rental_subtotal' => 'decimal:2', 'additional_charges_total' => 'decimal:2', 'total_amount' => 'decimal:2', 'deposit_required' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance_due' => 'decimal:2', 'deposit_received' => 'decimal:2', 'deposit_retained' => 'decimal:2', 'deposit_refunded' => 'decimal:2'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(ContractDriver::class);
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(ContractAcceptance::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(ContractCharge::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ContractStatusHistory::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class);
    }

    public function damages(): HasMany
    {
        return $this->hasMany(DamageReport::class);
    }

    public function vehicleBlock(): HasOne
    {
        return $this->hasOne(VehicleBlock::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function depositTransactions(): HasMany
    {
        return $this->hasMany(DepositTransaction::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
