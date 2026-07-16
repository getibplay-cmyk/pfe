<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'is_platform_admin' => ['prohibited'],
            'password' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role_id' => ['required', 'integer'],
            'agency_id' => ['nullable', 'integer'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
