<?php

namespace App\Enums;

enum VehicleColorReviewDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Ignored = 'ignored';
}
