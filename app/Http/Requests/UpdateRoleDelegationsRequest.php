<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleDelegationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delegate', Role::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct'],
        ];
    }
}
