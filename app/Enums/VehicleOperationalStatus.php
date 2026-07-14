<?php

namespace App\Enums;

enum VehicleOperationalStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case OutOfService = 'out_of_service';
    case Archived = 'archived';
}
