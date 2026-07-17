<?php

namespace App\Enums;

enum VehicleBlockType: string
{
    case Reservation = 'reservation';
    case Manual = 'manual';
    case Contract = 'contract';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Reservation => 'Réservation',
            self::Manual => 'Bloc manuel',
            self::Contract => 'Contrat',
            self::Maintenance => 'Maintenance',
        };
    }
}
