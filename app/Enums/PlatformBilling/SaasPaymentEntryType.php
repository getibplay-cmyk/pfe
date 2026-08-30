<?php

namespace App\Enums\PlatformBilling;

enum SaasPaymentEntryType: string
{
    case Payment = 'payment';
    case Reversal = 'reversal';
}
