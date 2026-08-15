<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FleetReallocationProposal extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'proposal_id',
        'idempotency_key',
        'schema_version',
        'source_kind',
        'solver_name',
        'solver_version',
        'solver_status',
        'qualification_decision',
        'qualification_commit',
        'evidence_commit',
        'generated_at',
        'as_of_date',
        'target_date',
        'forecast_horizon',
        'distance_unit',
        'data_status',
        'forecast_model_name',
        'forecast_model_version',
        'forecast_reference_sha256',
        'forecast_local_status',
        'cancellation_model_name',
        'cancellation_gate_decision',
        'presence_probability',
        'presence_reason',
        'node_count',
        'move_line_count',
        'relocated_vehicle_count',
        'total_demand',
        'served_demand',
        'unserved_demand',
        'service_rate',
        'relocation_cost_centimes',
        'decision_cost_centimes',
        'solver_runtime_ms',
        'canonical_payload_sha256',
        'content_sha256',
        'byte_size',
        'stored_path',
        'original_name',
        'validation_status',
        'local_validation_status',
        'operational_effect',
        'imported_by',
        'imported_at',
    ];

    protected $hidden = [
        'idempotency_key',
        'forecast_reference_sha256',
        'canonical_payload_sha256',
        'content_sha256',
        'stored_path',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'immutable_datetime',
            'as_of_date' => 'immutable_date',
            'target_date' => 'immutable_date',
            'forecast_horizon' => 'integer',
            'presence_probability' => 'decimal:6',
            'node_count' => 'integer',
            'move_line_count' => 'integer',
            'relocated_vehicle_count' => 'integer',
            'total_demand' => 'integer',
            'served_demand' => 'integer',
            'unserved_demand' => 'integer',
            'service_rate' => 'decimal:6',
            'relocation_cost_centimes' => 'integer',
            'decision_cost_centimes' => 'integer',
            'solver_runtime_ms' => 'decimal:6',
            'byte_size' => 'integer',
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'proposal_id';
    }

    public function moves(): HasMany
    {
        return $this->hasMany(FleetReallocationMove::class)->orderBy('row_position');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(FleetReallocationDecision::class);
    }

    public function runtimeRun(): HasOne
    {
        return $this->hasOne(FleetReallocationRun::class, 'fleet_reallocation_proposal_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function reviewStatus(): string
    {
        return $this->decision?->decision->value ?? 'pending';
    }
}
