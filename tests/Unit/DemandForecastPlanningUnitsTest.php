<?php

namespace Tests\Unit;

use App\Models\DemandForecast;
use App\Models\DemandForecastExecutionRun;
use App\Models\DemandForecastRun;
use App\Support\Intelligence\DemandForecasting\DemandForecastPlanningUnits;
use App\Support\Intelligence\FleetReallocation\DemandForecastCoverageValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemandForecastPlanningUnitsTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_conditional_mean_is_preserved_and_rounded_up_without_float_math(
        string $value,
        int $expected,
    ): void {
        $result = (new DemandForecastPlanningUnits)->convert($value);

        $this->assertSame($value, $result->conditionalMean);
        $this->assertSame($expected, $result->planningVehicleUnits);
        $this->assertSame('Départs prévus', $result::SIGNAL_LABEL);
        $this->assertSame(
            'Besoin de planification arrondi à l’unité supérieure',
            $result::PLANNING_LABEL,
        );
    }

    public static function validValues(): array
    {
        return [
            'zero' => ['0.000000', 0],
            'integer' => ['12.000000', 12],
            'fraction' => ['12.000001', 13],
            'small fraction' => ['0.000001', 1],
            'negative clamped' => ['-2.500000', 0],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_nan_infinity_and_non_decimal_values_are_rejected(string|float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DemandForecastPlanningUnits)->convert($value);
    }

    public static function invalidValues(): array
    {
        return [
            'NaN text' => ['NaN'],
            'positive infinity text' => ['INF'],
            'negative infinity text' => ['-INF'],
            'scientific notation' => ['1e2'],
            'NaN float' => [NAN],
            'infinite float' => [INF],
        ];
    }

    public function test_forecast_coverage_rejects_an_incompatible_horizon(): void
    {
        $execution = new DemandForecastExecutionRun(['agency_id' => 10]);
        $run = new DemandForecastRun([
            'agency_id' => 10,
            'validation_status' => 'validated',
            'target_semantics' => 'observed_departures',
            'result_count' => 7,
            'as_of_date' => '2026-08-30',
        ]);
        $rows = new Collection;
        foreach (range(1, 7) as $horizon) {
            $rows->push(new DemandForecast([
                'horizon' => $horizon === 4 ? 5 : $horizon,
                'target_date' => CarbonImmutable::parse('2026-08-30')->addDays($horizon),
                'vehicle_category_scope' => 'all',
                'conditional_mean' => '4.250000',
                'demand_semantics' => 'observed_departures',
            ]));
        }
        $run->setRelation('forecasts', $rows);
        $execution->setRelation('forecastRun', $run);

        $this->assertFalse((new DemandForecastCoverageValidator(
            new DemandForecastPlanningUnits,
        ))->compatible($execution));
    }
}
