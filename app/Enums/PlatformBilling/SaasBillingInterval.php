<?php

namespace App\Enums\PlatformBilling;

enum SaasBillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
