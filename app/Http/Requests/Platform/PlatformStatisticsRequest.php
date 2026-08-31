<?php

namespace App\Http\Requests\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PlatformStatisticsRequest extends FormRequest
{
    private const ALLOWED_QUERY_KEYS = ['date_from', 'date_to'];

    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_active && $this->user()->is_platform_admin);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from', now()->subMonths(5)->startOfMonth()->toDateString()),
            'date_to' => $this->input('date_to', today()->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->query->all()), self::ALLOWED_QUERY_KEYS);
            if ($unknown !== []) {
                $validator->errors()->add('filters', 'Un filtre non pris en charge a été transmis.');
            }
            if ($validator->errors()->hasAny(['date_from', 'date_to'])) {
                return;
            }

            $startsAt = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                (string) $this->input('date_from'),
                config('app.timezone'),
            )->startOfDay();
            $endsAt = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                (string) $this->input('date_to'),
                config('app.timezone'),
            )->addDay()->startOfDay();
            if ($startsAt->diffInDays($endsAt) > 366) {
                $validator->errors()->add('date_to', 'La période ne peut pas dépasser 366 jours.');
            }
        }];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $this->validated('date_from'),
            config('app.timezone'),
        )->startOfDay();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $this->validated('date_to'),
            config('app.timezone'),
        )->addDay()->startOfDay();
    }
}
