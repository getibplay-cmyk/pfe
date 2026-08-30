<?php

namespace App\Support\Intelligence\DemandForecasting;

use InvalidArgumentException;

class DemandForecastPlanningUnits
{
    public function convert(string|int|float $conditionalMean): DemandForecastPlanningValue
    {
        if (is_float($conditionalMean) && ! is_finite($conditionalMean)) {
            throw new InvalidArgumentException('FORECAST_VALUE_NOT_FINITE');
        }

        $original = trim((string) $conditionalMean);
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?$/D', $original) !== 1) {
            throw new InvalidArgumentException('FORECAST_VALUE_INVALID');
        }

        if (str_starts_with($original, '-')) {
            return new DemandForecastPlanningValue($original, 0);
        }

        [$whole, $fraction] = array_pad(explode('.', $original, 2), 2, '');
        $units = (int) $whole;
        if ($fraction !== '' && trim($fraction, '0') !== '') {
            $units++;
        }

        return new DemandForecastPlanningValue($original, $units);
    }
}
