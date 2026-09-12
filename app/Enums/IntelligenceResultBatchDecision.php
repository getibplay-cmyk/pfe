<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum IntelligenceResultBatchDecision: string
{
    case AcceptedForDemoReview = 'accepted_for_demo_review';
    case Rejected = 'rejected';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::AcceptedForDemoReview => UiText::t('Accepté pour revue de démonstration'),
            self::Rejected => UiText::t('Rejeté'),
        });
    }
}
