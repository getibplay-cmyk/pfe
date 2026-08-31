<?php

namespace App\Enums;

enum FleetReallocationRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En attente de traitement',
            self::Running => 'Calcul en cours',
            self::Succeeded => 'Calcul terminé',
            self::Failed => 'Calcul non abouti',
        };
    }
}
