<?php

namespace App\Enums;

enum InspectionItemCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';
    case Missing = 'missing';
    case NotChecked = 'not_checked';
}
