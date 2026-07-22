<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role && ($this->user()?->can('update', $role) ?? false);
    }

    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'tenant_id' => ['prohibited'],
            'slug' => ['prohibited'],
            'is_system' => ['prohibited'],
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('tenant_id', $this->user()->tenant_id)->ignore($role)],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct'],
            'is_active' => ['required', 'boolean'],
            'replacement_role_id' => ['nullable', 'integer', 'different:'.$role->id],
        ];
    }
}
