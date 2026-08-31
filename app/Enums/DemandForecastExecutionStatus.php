<?php

namespace App\Enums;

enum DemandForecastExecutionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En attente de traitement',
            self::Running => 'Prévision en cours',
            self::Succeeded => 'Prévision terminée',
            self::Failed => 'Prévision non aboutie',
        };
    }
}
