<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Support\SensitiveData\IdentityProtector;
use App\Support\Tenancy\AgencyAccess;

class CreateCustomer
{
    public function __construct(
        private readonly IdentityProtector $protector,
        private readonly AgencyAccess $agencyAccess,
    ) {}

    public function handle(array $data): Customer
    {
        $data['agency_id'] = $this->agencyAccess->optional($data['agency_id'] ?? null);
        $identity = $data['identity_number'] ?? null;
        unset($data['identity_number']);
        $customer = Customer::create($data);
        if ($identity) {
            $protected = $this->protector->protect($identity);
            $customer->forceFill(['identity_number_encrypted' => $protected['encrypted'], 'identity_number_hash' => $protected['hash']])->save();
        }

        return $customer;
    }
}
