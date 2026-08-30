<?php

namespace App\Enums;

enum AgencyDistanceSourceType: string
{
    case ManualVerified = 'manual_verified';

    public function label(): string
    {
        return match ($this) {
            self::ManualVerified => 'Saisie manuelle vérifiée',
        };
    }
}
