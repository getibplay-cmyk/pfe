<?php

namespace App\Enums;

enum VehiclePlateReviewDecision: string
{
    case Confirmed = 'confirmed';
    case Corrected = 'corrected';
    case Ignored = 'ignored';
}
