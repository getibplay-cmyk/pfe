<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

use Throwable;

final class RentalUsageAnomalyRuntimeReadiness
{
    public function ready(): bool
    {
        try {
            $timeout = (int) config('intelligence.rental_usage_anomaly.runtime_timeout_seconds');

            return (bool) config('intelligence.rental_usage_anomaly.enabled')
                && (string) config('intelligence.rental_usage_anomaly.python_binary') !== ''
                && is_file((string) config('intelligence.rental_usage_anomaly.runtime_script'))
                && (int) config('intelligence.rental_usage_anomaly.minimum_rows') === RentalUsageAnomalyContract::MINIMUM_ROWS
                && $timeout >= 10
                && $timeout <= 120;
        } catch (Throwable) {
            return false;
        }
    }
}
