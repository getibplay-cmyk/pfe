<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum VehicleBlockStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::Active => 'Actif',
            self::Released => UiText::t('Libéré'),
            self::Cancelled => UiText::t('Annulé'),
        });
    }
}
