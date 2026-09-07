<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantOnboardingInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_active && $this->user()->is_platform_admin);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'saas_plan_id' => ['required', 'integer', Rule::exists('saas_plans', 'id')->where('is_active', true)],
            'trial_days' => ['required', 'integer', 'min:1', 'max:90'],
            'expires_in_hours' => ['required', 'integer', 'min:1', 'max:168'],
        ];
    }
}
