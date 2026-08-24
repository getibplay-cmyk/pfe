<?php

namespace App\Enums;

enum RentalUsageAnomalyRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
