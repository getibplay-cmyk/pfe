<?php

namespace App\Enums;

enum VehicleBlockStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
