<?php

namespace App\Enums\PlatformBilling;

enum TenantSubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<self> */
    public static function current(): array
    {
        return [self::Trialing, self::Active, self::PastDue, self::Suspended];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }
}
