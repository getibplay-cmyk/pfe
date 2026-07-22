<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalNotificationRecipient extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'internal_notification_recipients';

    protected $fillable = ['internal_notification_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(InternalNotification::class, 'internal_notification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
