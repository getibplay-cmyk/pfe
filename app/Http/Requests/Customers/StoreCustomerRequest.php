<?php

namespace App\Http\Requests\Customers;

use App\Enums\CustomerType;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'verification_status' => ['prohibited'],
            'agency_id' => ['required', 'integer'],
            'customer_type' => ['required', Rule::enum(CustomerType::class)],
            'first_name' => ['nullable', 'required_if:customer_type,individual', 'max:100'],
            'last_name' => ['nullable', 'required_if:customer_type,individual', 'max:100'],
            'company_name' => ['nullable', 'required_if:customer_type,company', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'max:50'],
            'address' => ['nullable', 'max:2000'],
            'city' => ['nullable', 'max:100'],
            'nationality' => ['nullable', 'max:100'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'identity_type' => ['nullable', 'max:50'],
            'identity_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('agency_id')) {
                return;
            }

            try {
                app(AgencyAccess::class)->required($this->input('agency_id'));
            } catch (ValidationException $exception) {
                foreach ($exception->errors()['agency_id'] ?? [] as $message) {
                    $validator->errors()->add('agency_id', $message);
                }
            }
        }];
    }
}
