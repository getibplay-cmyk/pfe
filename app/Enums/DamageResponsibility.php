<?php

namespace App\Enums;

enum DamageResponsibility: string
{
    case Pending = 'pending';
    case Customer = 'customer';
    case Agency = 'agency';
    case Insurance = 'insurance';
    case Unknown = 'unknown';
}
