<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class VehicleEconomicProfile extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['tenant_id', 'id'];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date', 'acquired_on' => 'immutable_date', 'acquisition_cost' => 'decimal:2', 'residual_value' => 'decimal:2', 'annual_insurance' => 'decimal:2', 'monthly_unrecorded_costs' => 'decimal:2'];
    }
}
