<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum InsurancePolicyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::Draft => 'Brouillon',
            self::Active => 'Active',
            self::Expired => UiText::t('Expirée'),
            self::Cancelled => UiText::t('Annulée'),
        });
    }
}
