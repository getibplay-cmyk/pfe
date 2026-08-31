<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTenantIntelligenceAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_active && $this->user()->is_platform_admin);
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'tenant_id' => ['prohibited'],
            'capability' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'changed_at' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_keys($this->rules());
            foreach (array_diff(array_keys($this->except(['_token', '_method'])), $allowed) as $key) {
                $validator->errors()->add($key, 'Ce champ n’est pas autorisé.');
            }
        }];
    }
}
