<?php

namespace App\Http\Requests;

use App\Models\Agency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agency = $this->route('agency');

        return $agency instanceof Agency && ($this->user()?->can('update', $agency) ?? false);
    }

    public function rules(): array
    {
        /** @var Agency $agency */
        $agency = $this->route('agency');

        return [
            'tenant_id' => ['prohibited'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash:ascii', Rule::unique('agencies')->where('tenant_id', $this->user()->tenant_id)->ignore($agency)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
