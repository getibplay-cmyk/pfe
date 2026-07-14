<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Support\SensitiveData\IdentityProtector;

class UpdateCustomerIdentity
{
    public function __construct(private readonly IdentityProtector $protector) {}

    public function handle(Customer $customer, string $number, ?string $type): Customer
    {
        $protected = $this->protector->protect($number);
        $customer->forceFill(['identity_type' => $type, 'identity_number_encrypted' => $protected['encrypted'], 'identity_number_hash' => $protected['hash']])->save();

        return $customer;
    }
}
