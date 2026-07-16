<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requestedAgency = $this->input('agency_id');

        return ($user?->hasPermission('report.view') ?? false)
            && ($user->agency_id === null || $requestedAgency === null || $requestedAgency === '' || $user->agency_id === (int) $requestedAgency);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to' => $this->input('date_to', today()->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $this->user()->tenant_id)],
        ];
    }
}
