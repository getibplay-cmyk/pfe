<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AcceptTenantOnboardingInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_slug' => mb_strtolower(trim((string) $this->input('company_slug'))),
            'agency_code' => mb_strtoupper(trim((string) $this->input('agency_code'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'company_slug' => ['required', 'string', 'max:100', 'alpha_dash:ascii', Rule::unique('tenants', 'slug')],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:2000'],
            'agency_code' => ['required', 'string', 'max:30', 'alpha_dash:ascii'],
            'agency_name' => ['required', 'string', 'max:255'],
            'agency_email' => ['nullable', 'email', 'max:255'],
            'agency_phone' => ['nullable', 'string', 'max:50'],
            'agency_address' => ['nullable', 'string', 'max:2000'],
            'owner_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
