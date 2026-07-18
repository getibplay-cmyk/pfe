<?php

namespace App\Models;

use App\Enums\InsuranceClaimStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InsuranceClaim extends Model
{
    use BelongsToTenant;

    protected $fillable = ['agency_id', 'insurance_policy_id', 'damage_report_id', 'rental_contract_id', 'claim_number', 'status', 'incident_at', 'reported_at', 'submitted_at', 'reviewed_at', 'claimed_amount', 'approved_amount', 'settled_amount', 'insurer_reference_encrypted', 'notes', 'created_by'];

    protected $hidden = ['insurer_reference_encrypted'];

    protected function casts(): array
    {
        return ['status' => InsuranceClaimStatus::class, 'incident_at' => 'immutable_datetime', 'reported_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'claimed_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'settled_amount' => 'decimal:2', 'insurer_reference_encrypted' => 'encrypted'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function damageReport(): BelongsTo
    {
        return $this->belongsTo(DamageReport::class);
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(InsuranceClaimStatusHistory::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
