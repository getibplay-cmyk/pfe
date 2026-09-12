<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum DemandForecastExecutionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::Queued => UiText::t('En attente de traitement'),
            self::Running => UiText::t('Prévision en cours'),
            self::Succeeded => UiText::t('Prévision terminée'),
            self::Failed => UiText::t('Prévision non aboutie'),
        });
    }
}
