<?php

namespace App\Enums\PlatformBilling;

enum SaasPaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
