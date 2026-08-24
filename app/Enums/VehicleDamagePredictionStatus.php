<?php

namespace App\Enums;

enum VehicleDamagePredictionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
