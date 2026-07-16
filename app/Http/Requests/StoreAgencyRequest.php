<?php

namespace App\Http\Requests;

use App\Models\Agency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Agency::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash:ascii', Rule::unique('agencies')->where('tenant_id', $this->user()->tenant_id)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
