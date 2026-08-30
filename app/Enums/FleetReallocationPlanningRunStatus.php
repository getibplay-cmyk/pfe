<?php

namespace App\Enums;

enum FleetReallocationPlanningRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
