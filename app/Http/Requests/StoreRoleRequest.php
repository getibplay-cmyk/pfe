<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'slug' => ['prohibited'],
            'is_system' => ['prohibited'],
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('tenant_id', $this->user()->tenant_id)],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct'],
        ];
    }
}
