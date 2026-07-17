<?php

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['is_primary' => $this->boolean('is_primary')]);
    }

    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer !== null && $this->user()?->can('update', $customer) === true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'verification_status' => ['prohibited'],
            'first_name' => ['required', 'max:100'],
            'last_name' => ['required', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'licence_number' => ['required', 'string', 'max:100'],
            'licence_category' => ['nullable', 'max:20'],
            'licence_issued_at' => ['nullable', 'date', 'before_or_equal:licence_expires_at'],
            'licence_expires_at' => ['required', 'date'],
            'is_primary' => ['required', 'boolean'],
        ];
    }
}
