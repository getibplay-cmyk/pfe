<?php

namespace App\Enums;

enum InspectionStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
}
