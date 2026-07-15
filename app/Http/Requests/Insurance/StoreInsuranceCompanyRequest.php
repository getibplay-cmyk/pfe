<?php

namespace App\Http\Requests\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class StoreInsuranceCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('insurance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
