<?php

namespace App\Models;

use App\Enums\AgencyDistanceSourceType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyDistance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'from_agency_id',
        'to_agency_id',
        'distance_km',
        'source_type',
        'source_reference',
        'verified_by_user_id',
        'verified_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:3',
            'source_type' => AgencyDistanceSourceType::class,
            'verified_at' => 'immutable_datetime',
            'active' => 'boolean',
        ];
    }

    public function fromAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'from_agency_id');
    }

    public function toAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'to_agency_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
