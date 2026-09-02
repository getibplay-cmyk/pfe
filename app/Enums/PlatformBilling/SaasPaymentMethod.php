<?php

namespace App\Enums\PlatformBilling;

enum SaasPaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Cmi = 'cmi';
    case Other = 'other';
}
