<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'currency' => $this->filled('currency') ? strtoupper((string) $this->input('currency')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['date_from', 'date_to'])) {
                return;
            }

            $from = CarbonImmutable::parse((string) $this->input('date_from'));
            $to = CarbonImmutable::parse((string) $this->input('date_to'));
            if ($from->diffInDays($to) > 365) {
                $validator->errors()->add('date_to', 'La période du rapport ne peut pas dépasser 366 jours.');
            }
        }];
    }
}
