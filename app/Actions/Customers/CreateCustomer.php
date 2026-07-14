<?php

namespace App\Actions\Customers;

use App\Models\Agency;
use App\Models\Customer;
use App\Support\SensitiveData\IdentityProtector;

class CreateCustomer
{
    public function __construct(private readonly IdentityProtector $protector) {}

    public function handle(array $data): Customer
    {
        if (! empty($data['agency_id'])) {
            Agency::findOrFail($data['agency_id']);
        }
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
