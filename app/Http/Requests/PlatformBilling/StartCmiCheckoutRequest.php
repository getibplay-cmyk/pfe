<?php

namespace App\Http\Requests\PlatformBilling;

use Illuminate\Foundation\Http\FormRequest;

class StartCmiCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTenantOwner() === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
