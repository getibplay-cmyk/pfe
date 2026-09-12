<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum RentalUsageAnomalyReviewDecision: string
{
    case FollowUp = 'follow_up';
    case Dismissed = 'dismissed';
    case NeedsInformation = 'needs_information';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::FollowUp => UiText::t('À suivre'),
            self::Dismissed => UiText::t('Vérifié et écarté'),
            self::NeedsInformation => UiText::t('Informations complémentaires'),
        });
    }
}
