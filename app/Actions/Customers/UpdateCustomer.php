<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Support\Tenancy\AgencyAccess;

class UpdateCustomer
{
    public function __construct(
        private readonly AgencyAccess $agencyAccess,
        private readonly UpdateCustomerIdentity $identity,
    ) {}

    public function handle(Customer $customer, array $data): Customer
    {
        $data['agency_id'] = $this->agencyAccess->optional($data['agency_id'] ?? null);
        $identityNumber = $data['identity_number'] ?? null;
        unset($data['identity_number']);

        $customer->update($data);

        if ($identityNumber) {
            $this->identity->handle($customer, $identityNumber, $data['identity_type'] ?? null);
        }

        return $customer->refresh();
    }
}
