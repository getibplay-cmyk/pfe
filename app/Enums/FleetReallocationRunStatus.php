<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum FleetReallocationRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::Queued => UiText::t('En attente de traitement'),
            self::Running => UiText::t('Calcul en cours'),
            self::Succeeded => UiText::t('Calcul terminé'),
            self::Failed => UiText::t('Calcul non abouti'),
        });
    }
}
