<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetReallocationMove extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'fleet_reallocation_proposal_id',
        'row_position',
        'from_node_ref',
        'to_node_ref',
        'vehicles',
        'distance_km',
        'unit_cost_centimes',
        'total_cost_centimes',
        'reason_code',
        'operational_effect',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'row_position' => 'integer',
            'vehicles' => 'integer',
            'distance_km' => 'decimal:3',
            'unit_cost_centimes' => 'integer',
            'total_cost_centimes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(FleetReallocationProposal::class, 'fleet_reallocation_proposal_id');
    }
}
