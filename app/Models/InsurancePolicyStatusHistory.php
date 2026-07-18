<?php

namespace App\Models;

use App\Enums\InsurancePolicyStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsurancePolicyStatusHistory extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['agency_id', 'insurance_policy_id', 'from_status', 'to_status', 'reason', 'actor_id', 'changed_at'];

    protected function casts(): array
    {
        return ['from_status' => InsurancePolicyStatus::class, 'to_status' => InsurancePolicyStatus::class, 'changed_at' => 'immutable_datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
