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
        'due_at',
        'last_detected_at',
        'resolved_at',
        'resolution_reason',
        'occurrence_count',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'occurrence_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InternalNotification $notification): void {
            $notification->last_detected_at ??= $notification->occurred_at ?? now();
        });
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
