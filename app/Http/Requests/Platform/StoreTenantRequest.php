<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->is_platform_admin;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'status' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash:ascii', Rule::unique('tenants', 'slug')],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone:all'],
            'agency_code' => ['required', 'string', 'max:30', 'alpha_dash:ascii'],
            'agency_name' => ['required', 'string', 'max:255'],
            'agency_email' => ['nullable', 'email', 'max:255'],
            'agency_phone' => ['nullable', 'string', 'max:50'],
            'agency_address' => ['nullable', 'string', 'max:2000'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ];
    }
}
