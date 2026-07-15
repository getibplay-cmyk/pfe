<?php

namespace App\Enums;

enum InsuranceClaimStatus: string
{
    case Reported = 'reported';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Settled = 'settled';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Reported => 'Déclaré',
            self::Submitted => 'Soumis',
            self::UnderReview => 'En revue',
            self::Approved => 'Approuvé',
            self::Rejected => 'Rejeté',
            self::Settled => 'Réglé',
            self::Closed => 'Clôturé',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Reported => in_array($target, [self::Submitted, self::UnderReview], true),
            self::Submitted => $target === self::UnderReview,
            self::UnderReview => in_array($target, [self::Approved, self::Rejected], true),
            self::Approved => $target === self::Settled,
            self::Settled => $target === self::Closed,
            self::Rejected, self::Closed => false,
        };
    }
}
