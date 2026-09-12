<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicBookingRequest extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['tenant_id', 'id'];

    protected $hidden = ['contact', 'session_hash', 'review_note'];

    protected function casts(): array
    {
        return ['contact' => 'encrypted:array', 'review_note' => 'encrypted', 'quote_snapshot' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }
}
