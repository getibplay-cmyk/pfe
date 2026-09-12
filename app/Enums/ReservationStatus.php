<?php

namespace App\Enums;

use App\Support\Ui\UiText;

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
        return UiText::t(match ($this) {
            self::Draft => 'Brouillon',
            self::Pending => UiText::t('En attente'),
            self::Confirmed => UiText::t('Confirmée'),
            self::Converted => 'Convertie',
            self::Cancelled => UiText::t('Annulée'),
            self::Expired => UiText::t('Expirée'),
        });
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
