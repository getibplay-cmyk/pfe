<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Pending => 'En attente',
            self::Confirmed => 'Confirmée',
            self::Converted => 'Convertie',
            self::Cancelled => 'Annulée',
            self::Expired => 'Expirée',
        };
    }

    public function canBeConfirmed(): bool
    {
        return in_array($this, [self::Draft, self::Pending], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Pending, self::Confirmed], true);
    }
}
