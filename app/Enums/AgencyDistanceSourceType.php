<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum AgencyDistanceSourceType: string
{
    case ManualVerified = 'manual_verified';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::ManualVerified => UiText::t('Saisie manuelle vérifiée'),
        });
    }
}
