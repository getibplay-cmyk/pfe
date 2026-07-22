<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAgencyDelegation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['agency_id', 'role_id', 'delegated_by'];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function delegatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_by');
    }
}
