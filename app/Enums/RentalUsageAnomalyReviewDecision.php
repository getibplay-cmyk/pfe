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
            self::FollowUp => 'Conserver pour vérification',
            self::Dismissed => 'Écarter après vérification',
            self::NeedsInformation => 'Informations complémentaires requises',
        };
    }
}
