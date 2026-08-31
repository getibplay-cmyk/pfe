<?php

namespace App\Support\Intelligence\DemandForecasting;

use App\Support\Intelligence\IntelligencePseudonymizer;
use Throwable;

final class DemandForecastRuntimeReadiness
{
    public function __construct(
        private readonly DemandForecastModelArtifact $modelArtifact,
        private readonly IntelligencePseudonymizer $pseudonymizer,
    ) {}

    public function ready(): bool
    {
        try {
            return (bool) config('intelligence.demand_forecasting.runtime_enabled')
                && $this->pseudonymizer->configured()
                && $this->modelArtifact->configuredIsValid()
                && (string) config('intelligence.demand_forecasting.python_binary') !== ''
                && is_file((string) config('intelligence.demand_forecasting.runtime_script'));
        } catch (Throwable) {
            return false;
        }
    }
}
