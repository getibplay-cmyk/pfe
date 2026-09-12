<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum J11DemoDecision: string
{
    case AcceptedForDemoReview = 'accepted_for_demo_review';
    case Rejected = 'rejected';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::AcceptedForDemoReview => UiText::t('Accepté pour la revue de démonstration'),
            self::Rejected => UiText::t('Rejeté'),
        });
    }
}
