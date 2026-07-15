<?php

namespace App\Models;

use App\Enums\InsuranceClaimStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceClaimStatusHistory extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['agency_id', 'insurance_claim_id', 'from_status', 'to_status', 'actor_id', 'note', 'changed_at'];

    protected function casts(): array
    {
        return [
            'from_status' => InsuranceClaimStatus::class,
            'to_status' => InsuranceClaimStatus::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(InsuranceClaim::class, 'insurance_claim_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
