<?php

namespace App\Enums\PlatformBilling;

enum SaasGatewayEventResult: string
{
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
}
