<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['agency_id', 'rental_contract_id', 'document_id', 'version_number', 'terms_snapshot', 'pricing_snapshot', 'customer_snapshot', 'vehicle_snapshot', 'content_hash', 'change_reason', 'created_by', 'locked_at'];

    protected function casts(): array
    {
        return ['terms_snapshot' => 'array', 'pricing_snapshot' => 'array', 'customer_snapshot' => 'array', 'vehicle_snapshot' => 'array', 'locked_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(ContractAcceptance::class);
    }
}
