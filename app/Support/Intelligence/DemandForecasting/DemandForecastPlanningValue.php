<?php

namespace App\Support\Intelligence\DemandForecasting;

final readonly class DemandForecastPlanningValue
{
    public const SIGNAL_LABEL = 'Départs prévus';

    public const PLANNING_LABEL = 'Besoin de planification arrondi à l’unité supérieure';

    public function __construct(
        public string $conditionalMean,
        public int $planningVehicleUnits,
    ) {}
}
