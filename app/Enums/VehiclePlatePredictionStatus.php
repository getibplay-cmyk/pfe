<?php

namespace App\Enums;

enum VehiclePlatePredictionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
