<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SuspendTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->is_platform_admin;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
