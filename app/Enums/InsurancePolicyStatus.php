<?php

namespace App\Enums;

enum InsurancePolicyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Active => 'Active',
            self::Expired => 'Expirée',
            self::Cancelled => 'Annulée',
        };
    }
}
