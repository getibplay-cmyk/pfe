<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class InternalNotification extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'agency_id',
        'category',
        'priority',
        'title',
        'summary',
        'resource_type',
        'resource_id',
        'required_permission',
        'deduplication_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'internal_notification_recipients')
            ->withPivot(['tenant_id', 'read_at', 'created_at']);
    }
}
