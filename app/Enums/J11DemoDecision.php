<?php

namespace App\Enums;

enum J11DemoDecision: string
{
    case AcceptedForDemoReview = 'accepted_for_demo_review';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::AcceptedForDemoReview => 'Accepté pour la revue de démonstration',
            self::Rejected => 'Rejeté',
        };
    }
}
