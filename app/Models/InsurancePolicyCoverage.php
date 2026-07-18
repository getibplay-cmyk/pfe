<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InsurancePolicyCoverage extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['insurance_policy_id', 'coverage_type', 'label', 'limit_amount', 'deductible_amount', 'terms', 'archived_by'];

    protected function casts(): array
    {
        return ['limit_amount' => 'decimal:2', 'deductible_amount' => 'decimal:2', 'terms' => 'array'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }
}
