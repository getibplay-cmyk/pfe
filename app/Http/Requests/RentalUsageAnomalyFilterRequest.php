<?php

namespace App\Http\Requests;

use App\Enums\RentalUsageAnomalyReviewDecision;
use App\Models\RentalUsageAnomalyRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RentalUsageAnomalyFilterRequest extends FormRequest
{
    private const DEFAULT_PERIOD_DAYS = 90;

    private const MAX_PERIOD_DAYS = 366;

    public function authorize(): bool
    {
        $user = $this->user();
        if (! ($user?->can('viewAny', RentalUsageAnomalyRun::class) ?? false)) {
            return false;
        }

        $agency = filter_var($this->input('agency'), FILTER_VALIDATE_INT);

        return $agency === false
            || $user->agency_id === null
            || (int) $user->agency_id === $agency;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agency' => [
                'nullable',
                'integer',
                Rule::exists('agencies', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'review_state' => [
                'nullable',
                Rule::in([
                    'pending',
                    ...array_map(
                        fn (RentalUsageAnomalyReviewDecision $decision): string => $decision->value,
                        RentalUsageAnomalyReviewDecision::cases(),
                    ),
                ]),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'tenant' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'model' => ['prohibited'],
            'budget' => ['prohibited'],
            'score' => ['prohibited'],
            'run' => ['prohibited'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $declared = [
                'agency', 'date_from', 'date_to', 'review_state', 'page',
                'tenant', 'tenant_id', 'model', 'budget', 'score', 'run',
            ];
            foreach (array_diff(array_keys($this->query()), $declared) as $key) {
                $validator->errors()->add($key, 'Ce filtre n’est pas autorisé.');
            }

            if ($validator->errors()->hasAny(['date_from', 'date_to'])) {
                return;
            }

            [$from, $to] = $this->effectiveDates();
            if ($from->isAfter($to)) {
                $validator->errors()->add('date_to', 'La date de fin doit être postérieure ou égale à la date de début.');

                return;
            }

            if ($from->diffInDays($to) > self::MAX_PERIOD_DAYS) {
                $validator->errors()->add('date_to', 'La période de consultation ne peut pas dépasser 366 jours.');
            }
        }];
    }

    public function agencyId(): ?int
    {
        if ($this->user()->agency_id !== null) {
            return (int) $this->user()->agency_id;
        }

        $agency = $this->validated('agency');

        return $agency === null ? null : (int) $agency;
    }

    public function dateFrom(): CarbonImmutable
    {
        return $this->effectiveDates()[0];
    }

    public function dateTo(): CarbonImmutable
    {
        return $this->effectiveDates()[1];
    }

    public function reviewState(): ?string
    {
        $state = $this->validated('review_state');

        return is_string($state) && $state !== '' ? $state : null;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function effectiveDates(): array
    {
        $timezone = (string) config('app.timezone');
        $to = $this->input('date_to')
            ? CarbonImmutable::parse((string) $this->input('date_to'), $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $from = $this->input('date_from')
            ? CarbonImmutable::parse((string) $this->input('date_from'), $timezone)->startOfDay()
            : $to->subDays(self::DEFAULT_PERIOD_DAYS);

        return [$from, $to];
    }
}
