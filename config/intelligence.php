<?php

return [
    'export_hmac_key' => env('INTELLIGENCE_EXPORT_HMAC_KEY'),

    'dataset_exports' => [
        'disk' => 'local',
        'manifest_version' => '1.0.0',
        'max_rows' => 10000,
    ],

    'result_batches' => [
        'disk' => 'local',
        'schema_version' => '1.0.0',
        'max_upload_kilobytes' => 1024,
        'synthetic_only' => true,
        'automatic_actions_allowed' => false,
        'ready_for_saas' => false,
        'production_allowed' => false,
        'decision_effect' => 'NO_OPERATIONAL_ACTION',
    ],

    'rule_baseline' => [
        'name' => 'rental_anomaly_rules',
        'version' => '1.0.0',
        'thresholds' => [
            'late_hours' => '4.000000',
            'km_per_day' => '500.000000',
            'fuel_drop_pct' => '25.000000',
        ],
    ],

    'frozen_model' => [
        'name' => 'rental_anomaly_iforest',
        'version' => '0.1.0',
        'algorithm' => 'Isolation Forest',
        'threshold' => '0.5740760891923362',
        'compute' => 'CPU',
        'training_data' => 'synthetic',
    ],

    // J12 is intentionally not environment-configurable. Tests may override
    // this value in memory to prove the isolated synthetic workflow.
    'contract_demo' => [
        'enabled' => false,
        'synthetic_only' => true,
        'operational_actions_allowed' => false,
        'ready_for_saas' => false,
        'contract_version' => '1.0.0',
        'decision_effect' => 'NO_OPERATIONAL_ACTION',
    ],
];
