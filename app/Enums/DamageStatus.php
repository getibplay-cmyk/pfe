<?php

namespace App\Enums;

enum DamageStatus: string
{
    case Reported = 'reported';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
