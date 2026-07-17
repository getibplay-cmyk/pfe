<?php

namespace App\Enums;

enum VehicleBlockStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Released => 'Libéré',
            self::Cancelled => 'Annulé',
        };
    }
}
