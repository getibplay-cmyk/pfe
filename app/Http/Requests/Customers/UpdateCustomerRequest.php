<?php

namespace App\Http\Requests\Customers;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        return parent::rules();
    }
}
