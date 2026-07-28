<?php

return [
    'export_hmac_key' => env('INTELLIGENCE_EXPORT_HMAC_KEY'),

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
];
