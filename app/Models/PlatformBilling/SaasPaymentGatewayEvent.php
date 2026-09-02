<?php

namespace App\Models\PlatformBilling;

use App\Enums\PlatformBilling\SaasGatewayEventResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPaymentGatewayEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'processing_result' => SaasGatewayEventResult::class,
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SaasPaymentAttempt::class, 'saas_payment_attempt_id');
    }
}
