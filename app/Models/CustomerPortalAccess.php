<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerPortalAccess extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['tenant_id'];

    protected $hidden = ['session_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'session_expires_at' => 'immutable_datetime'];
    }
}
