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
            self::Queued => 'En attente du worker',
            self::Running => 'Inférence HGB en cours',
            self::Succeeded => 'Inférence terminée',
            self::Failed => 'Échec contrôlé',
        };
    }
}
