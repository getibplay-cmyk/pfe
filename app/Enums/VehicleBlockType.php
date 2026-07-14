<?php

namespace App\Enums;

enum VehicleBlockType: string
{
    case Reservation = 'reservation';
    case Manual = 'manual';
    case Contract = 'contract';
    case Maintenance = 'maintenance';
}
