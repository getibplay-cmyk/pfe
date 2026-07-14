<?php

namespace App\Models;

use App\Enums\AcceptanceMethod;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAcceptance extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['rental_contract_id', 'contract_version_id', 'accepted_by_name', 'acceptance_method', 'consent_text_version', 'accepted_at', 'ip_address', 'user_agent', 'signature_document_id', 'content_hash', 'created_by'];

    protected $hidden = ['ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['acceptance_method' => AcceptanceMethod::class, 'accepted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function contractVersion(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class);
    }

    public function signatureDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signature_document_id');
    }
}
