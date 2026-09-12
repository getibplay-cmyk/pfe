<?php

namespace App\Enums;

use App\Support\Ui\UiText;

enum VehicleBlockType: string
{
    case Reservation = 'reservation';
    case Manual = 'manual';
    case Contract = 'contract';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return UiText::t(match ($this) {
            self::Reservation => UiText::t('Réservation'),
            self::Manual => UiText::t('Bloc manuel'),
            self::Contract => UiText::t('Contrat'),
            self::Maintenance => 'Maintenance',
        });
    }
}
