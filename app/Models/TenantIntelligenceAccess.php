<?php

namespace App\Models;

use App\Enums\IntelligenceCapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantIntelligenceAccess extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'capability' => IntelligenceCapability::class,
            'enabled' => 'boolean',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
