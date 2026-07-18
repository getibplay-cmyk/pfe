<?php

namespace App\Http\Requests\Insurance;

use Illuminate\Foundation\Http\FormRequest;

class CancelInsurancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cancel', $this->route('policy')) ?? false;
    }

    public function rules(): array
    {
        return ['tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'], 'status' => ['prohibited'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
