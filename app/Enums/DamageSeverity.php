<?php

namespace App\Enums;

enum DamageSeverity: string
{
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Major = 'major';
    case Critical = 'critical';
}
