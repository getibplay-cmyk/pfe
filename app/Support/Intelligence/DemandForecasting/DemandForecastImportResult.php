<?php

namespace App\Support\Intelligence\DemandForecasting;

use App\Models\DemandForecastRun;

final readonly class DemandForecastImportResult
{
    public function __construct(
        public DemandForecastRun $run,
        public bool $created,
    ) {}
}
