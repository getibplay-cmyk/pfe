<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractExtension extends Model
{
    use BelongsToTenant;

    protected $guarded = ['tenant_id', 'id'];

    protected $hidden = ['offer_snapshot', 'offer_hash', 'document_hash', 'customer_note', 'agency_note', 'resolution_note'];

    protected function casts(): array
    {
        return ['requested_return_at' => 'immutable_datetime', 'original_return_at' => 'immutable_datetime', 'offered_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'additional_amount' => 'decimal:2', 'offer_snapshot' => 'encrypted:array', 'customer_note' => 'encrypted', 'agency_note' => 'encrypted', 'resolution_note' => 'encrypted'];
    }

    public function rentalContract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function label(): string
    {
        return __(match ($this->status) {
            'requested' => 'À examiner par l’agence', 'offered' => $this->expires_at->isPast() ? 'Proposition expirée' : 'À accepter par le locataire',
            'accepted' => 'Prolongation appliquée', 'rejected' => 'Demande refusée', 'cancelled' => 'Demande retirée', default => 'État inconnu',
        });
    }
}
