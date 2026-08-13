<?php

namespace App\Enums;

enum IntelligenceResultBatchDecision: string
{
    case AcceptedForDemoReview = 'accepted_for_demo_review';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::AcceptedForDemoReview => 'Accepté pour revue de démonstration',
            self::Rejected => 'Rejeté',
        };
    }
}
