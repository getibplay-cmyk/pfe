<?php

namespace App\Support\Intelligence\DemandForecasting;

use App\Enums\DemandForecastExecutionStatus;
use App\Models\DemandForecastExecutionRun;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

final class DemandForecastPlanningPresenter
{
    public function __construct(private readonly DemandForecastPlanningUnits $planningUnits) {}

    /** @return array<string, mixed>|null */
    public function latestForAgency(int $agencyId): ?array
    {
        $today = CarbonImmutable::now(DemandForecastContract::TIMEZONE)->startOfDay();
        $run = DemandForecastExecutionRun::query()
            ->with(['agency', 'forecastRun.forecasts'])
            ->where('agency_id', $agencyId)
            ->where('status', DemandForecastExecutionStatus::Succeeded->value)
            ->whereHas('forecastRun', fn ($query) => $query->whereDate(
                'as_of_date',
                $today->toDateString(),
            ))
            ->latest('finished_at')
            ->latest('id')
            ->first();

        return $run === null ? null : $this->succeeded($run);
    }

    /** @return array<string, mixed> */
    public function succeeded(DemandForecastExecutionRun $run): array
    {
        $run->loadMissing(['agency', 'forecastRun.forecasts']);
        if ($run->status !== DemandForecastExecutionStatus::Succeeded
            || $run->agency === null
            || $run->forecastRun === null
            || $run->forecastRun->generated_at === null) {
            throw new UnexpectedValueException('Forecast result is incomplete.');
        }

        $asOfDate = $run->forecastRun->as_of_date;
        if ($asOfDate === null) {
            throw new UnexpectedValueException('Forecast result is incomplete.');
        }

        $rows = $run->forecastRun->forecasts->sortBy('horizon')->values();
        if ($rows->count() !== 7) {
            throw new UnexpectedValueException('Forecast result count is invalid.');
        }

        $forecasts = [];
        foreach ($rows as $position => $forecast) {
            $horizon = $position + 1;
            $value = (string) $forecast->conditional_mean;
            if ($forecast->horizon !== $horizon
                || $forecast->target_date?->toDateString() !== $asOfDate->addDays($horizon)->toDateString()
                || preg_match('/^(?:0|[1-9][0-9]{0,7})\.[0-9]{6}$/D', $value) !== 1
                || str_starts_with($value, '-')) {
                throw new UnexpectedValueException('Forecast result values are invalid.');
            }

            $this->planningUnits->convert($value);

            $forecasts[] = [
                'date' => $forecast->target_date->toDateString(),
                'predicted_demand' => $value,
            ];
        }

        return [
            'status' => DemandForecastExecutionStatus::Succeeded->value,
            'generated_at' => $run->forecastRun->generated_at
                ->setTimezone(DemandForecastContract::TIMEZONE)
                ->toIso8601String(),
            'scope' => ['agency' => (string) $run->agency->name],
            'forecasts' => $forecasts,
            'message' => 'Les prévisions sont disponibles pour préparer le planning.',
        ];
    }

    /** @return array<string, mixed> */
    public function state(DemandForecastExecutionRun $run, string $message): array
    {
        $run->loadMissing('agency');
        if ($run->agency === null) {
            throw new UnexpectedValueException('Forecast scope is incomplete.');
        }

        return [
            'status' => $run->status->value,
            'generated_at' => null,
            'scope' => ['agency' => (string) $run->agency->name],
            'forecasts' => [],
            'message' => $message,
        ];
    }
}
