<?php

namespace App\Support\Intelligence\DemandForecasting;

final class DemandForecastContract
{
    public const MANIFEST_VERSION = '1.0.0';

    public const DATASET_SCHEMA_VERSION = '1.0';

    public const DATASET_VERSION = 'rentfleet-demand-history-v1.0.0';

    public const PREPROCESSING_VERSION = 'rentfleet-demand-preprocessing-v1.0.0';

    public const RESULT_SCHEMA_VERSION = '1.0.0';

    public const MODEL_NAME = 'hgb_poisson::regularized';

    public const MODEL_VERSION = 'j5-v1';

    public const MODEL_ARTIFACT_SHA256 = '992217b4887623ca924a3dc36686c69ab616634aace64cf993ad50b61ace6802';

    public const FRAMEWORK = 'scikit-learn';

    public const FRAMEWORK_VERSION = '1.6.1';

    public const EXPLANATION_METHOD = 'one_at_a_time_sensitivity_v1';

    public const TARGET = 'observed_departures';

    public const VEHICLE_CATEGORY_SCOPE = 'all';

    public const TIMEZONE = 'Africa/Casablanca';

    public const DISTANCE_UNIT = 'km';

    public const MINIMUM_HISTORY_DAYS = 35;

    public const MAXIMUM_HISTORY_DAYS = 731;

    public const PUBLIC_WAPE = '0.152342';

    public const PUBLIC_MASE = '0.829556';

    public const PUBLIC_INTERVAL_COVERAGE = '0.860700';

    public const OPERATIONAL_EFFECT = 'NO_OPERATIONAL_ACTION';

    /** @return list<string> */
    public static function snapshotHeaders(): array
    {
        return [
            'schema_version',
            'dataset_version',
            'preprocessing_version',
            'series_id',
            'tenant_key',
            'agency_key',
            'vehicle_category',
            'date_local',
            'observed_departures',
            'observation_available',
            'timezone',
            'distance_unit',
        ];
    }

    /** @return list<string> */
    public static function explainableFeatures(): array
    {
        return [
            'lag_1_at_cutoff',
            'lag_2_at_cutoff',
            'lag_3_at_cutoff',
            'lag_7_at_cutoff',
            'lag_14_at_cutoff',
            'lag_28_at_cutoff',
            'seasonal_lag_target_minus_7',
            'rolling_mean_7_at_cutoff',
            'rolling_mean_28_at_cutoff',
            'rolling_median_7_at_cutoff',
            'rolling_median_28_at_cutoff',
            'rolling_std_7_at_cutoff',
            'rolling_std_28_at_cutoff',
            'target_is_weekend',
        ];
    }
}
