<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\Driver;
use App\Support\SensitiveData\IdentityProtector;
use App\Support\Tenancy\AgencyAccess;

class CreateDriver
{
    public function __construct(
        private readonly IdentityProtector $protector,
        private readonly AgencyAccess $agencyAccess,
    ) {}

    public function handle(Customer $customer, array $data): Driver
    {
        $this->agencyAccess->optional($customer->agency_id);
        $protected = $this->protector->protect($data['licence_number']);
        unset($data['licence_number']);

        return Driver::forceCreate([...$data, 'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'licence_number_encrypted' => $protected['encrypted'], 'licence_number_hash' => $protected['hash']]);
    }
}
