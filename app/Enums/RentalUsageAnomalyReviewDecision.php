<?php

namespace App\Enums;

enum RentalUsageAnomalyReviewDecision: string
{
    case FollowUp = 'follow_up';
    case Dismissed = 'dismissed';
    case NeedsInformation = 'needs_information';

    public function label(): string
    {
        return match ($this) {
            self::FollowUp => 'À suivre',
            self::Dismissed => 'Vérifié et écarté',
            self::NeedsInformation => 'Informations complémentaires',
        };
    }
}
