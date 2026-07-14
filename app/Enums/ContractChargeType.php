<?php

namespace App\Enums;

enum ContractChargeType: string
{
    case BaseRental = 'base_rental';
    case LateFee = 'late_fee';
    case ExtraKilometre = 'extra_kilometre';
    case MissingFuel = 'missing_fuel';
    case Cleaning = 'cleaning';
    case Damage = 'damage';
    case Other = 'other';
}
