<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum VehicleDamageReviewDecision: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case NewPhotoRequired = 'new_photo_required';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::Confirmed => UiText::t('Zone candidate confirmée visuellement'),
            self::Rejected => UiText::t('Zone candidate rejetée'),
            self::NewPhotoRequired => UiText::t('Nouvelle photo requise'),
        });
    }
}
