<?php

namespace App\Enums;

enum VehicleDamageReviewDecision: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case NewPhotoRequired = 'new_photo_required';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Zone candidate confirmée visuellement',
            self::Rejected => 'Zone candidate rejetée',
            self::NewPhotoRequired => 'Nouvelle photo requise',
        };
    }
}
