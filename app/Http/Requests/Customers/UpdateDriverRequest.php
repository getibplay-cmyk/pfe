<?php

namespace App\Http\Requests\Customers;

class UpdateDriverRequest extends StoreDriverRequest
{
    public function authorize(): bool
    {
        $driver = $this->route('driver');

        return $driver !== null && $this->user()?->can('update', $driver) === true;
    }

    public function rules(): array
    {
        return [...parent::rules(), 'licence_number' => ['nullable', 'string', 'max:100']];
    }
}
