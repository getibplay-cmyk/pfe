<?php

namespace App\Http\Requests;

use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DemandHistoryExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requestedAgency = (int) $this->input('agency_id');

        return ($user?->hasPermission('prediction.export') ?? false)
            && ($user->agency_id === null || $user->agency_id === $requestedAgency);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'agency_id' => $this->input('agency_id', $this->user()?->agency_id),
            'date_from' => $this->input('date_from', today()->subDays(179)->toDateString()),
            'date_to' => $this->input('date_to', today()->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'agency_id' => [
                'required',
                'integer',
                Rule::exists('agencies', 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:today'],
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
            $days = $from->diffInDays($to) + 1;
            if ($days < DemandForecastContract::MINIMUM_HISTORY_DAYS
                || $days > DemandForecastContract::MAXIMUM_HISTORY_DAYS) {
                $validator->errors()->add('date_to', 'La période doit contenir entre 35 et 731 jours inclus.');
            }
        }];
    }
}
