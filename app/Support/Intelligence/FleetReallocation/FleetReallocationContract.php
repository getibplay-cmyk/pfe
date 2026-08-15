<?php

namespace App\Support\Intelligence\FleetReallocation;

final class FleetReallocationContract
{
    public const SCHEMA_VERSION = '1.0.0';

    public const SOURCE_KIND = 'synthetic_demo';

    public const SOLVER_NAME = 'ortools_simple_min_cost_flow';

    public const SOLVER_VERSION = '9.15.6755';

    public const SOLVER_STATUS = 'OPTIMAL';

    public const QUALIFICATION_DECISION = 'QUALIFIED_FOR_CONSULTATIVE_SAAS_INTEGRATION_REVIEW';

    public const QUALIFICATION_COMMIT = 'f71a80ac657c5ed58a8147e8535bdba60dddde0d';

    public const EVIDENCE_COMMIT = '77479105049fa183f9e032e3207017b5348f6f1b';

    public const DATA_STATUS = 'SYNTHETIC_DEMO_NOT_RENTFLEET_HISTORY';

    public const DISTANCE_UNIT = 'km';

    public const FORECAST_MODEL = 'hgb_poisson::regularized';

    public const FORECAST_VERSION = 'j5-v1';

    public const FORECAST_LOCAL_STATUS = 'not_available_pending_real_history';

    public const CANCELLATION_MODEL = 'cancellation_risk_catboost';

    public const CANCELLATION_DECISION = 'RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION';

    public const PRESENCE_PROBABILITY = '1.000000';

    public const PRESENCE_REASON = 'CATBOOST_RESEARCH_GATE_NOT_PASSED_CONSERVATIVE_NO_DISCOUNT';

    public const RELOCATION_COST_CENTIMES_PER_KM = 500;

    public const UNSERVED_PENALTY_CENTIMES = 1_000_000;

    public const LOCAL_VALIDATION_STATUS = 'NOT_VALIDATED_NO_REAL_HISTORY';

    public const OPERATIONAL_EFFECT = 'NO_OPERATIONAL_ACTION';

    public const MOVE_REASON = 'EFFECTIVE_DEMAND_IMBALANCE';
}
