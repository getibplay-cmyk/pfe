<?php

namespace App\Enums;

enum ContractChargeStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
