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

    'demand_forecasting' => [
        'disk' => 'local',
        'max_upload_kilobytes' => 512,
        'runtime_enabled' => env('DEMAND_FORECAST_RUNTIME_ENABLED', true),
        'runtime_queue' => 'intelligence',
        'runtime_timeout_seconds' => 60,
        'runtime_stale_after_seconds' => 600,
        'python_binary' => env(
            'DEMAND_FORECAST_PYTHON_BINARY',
            env('INTELLIGENCE_PYTHON_BINARY', 'python'),
        ),
        'runtime_script' => base_path('scripts/intelligence/run_demand_forecast.py'),
        'model_bundle_path' => env(
            'DEMAND_FORECAST_MODEL_PATH',
            storage_path('app/private/intelligence/models/demand_forecast_munich_j5_v1.0.joblib'),
        ),
        'mode' => 'consultative_shadow',
        'automatic_actions_allowed' => false,
        'production_claim_allowed' => false,
        'distance_unit' => 'km',
        'decision_effect' => 'NO_OPERATIONAL_ACTION',
    ],

    'fleet_reallocation' => [
        'disk' => 'local',
        'schema_version' => '1.0.0',
        'max_upload_kilobytes' => 1024,
        'runtime_enabled' => true,
        'runtime_queue' => 'intelligence',
        'runtime_timeout_seconds' => 30,
        'runtime_stale_after_seconds' => 600,
        'python_binary' => env('INTELLIGENCE_PYTHON_BINARY', 'python'),
        'runtime_script' => base_path('scripts/intelligence/run_fleet_reallocation.py'),
        'synthetic_demo_only' => true,
        'automatic_actions_allowed' => false,
        'operational_table_writes_allowed' => false,
        'local_validation_status' => 'NOT_VALIDATED_NO_REAL_HISTORY',
        'decision_effect' => 'NO_OPERATIONAL_ACTION',
    ],

    'vehicle_color_v8' => [
        'enabled' => env('RENTFLEET_COLOR_V8_ENABLED', false),
        'disk' => 'local',
        'runtime_queue' => 'intelligence',
        'runtime_timeout_seconds' => 30,
        'runtime_stale_after_seconds' => 600,
        'image_sanitizer_timeout_seconds' => 15,
        'max_upload_kilobytes' => 8192,
        'max_image_dimension' => 8000,
        'max_stored_image_dimension' => 2048,
        'rate_limits' => [
            'user_per_minute' => env('COLOR_V8_USER_RATE_LIMIT_PER_MINUTE', 5),
            'scope_per_hour' => env('COLOR_V8_SCOPE_RATE_LIMIT_PER_HOUR', 30),
        ],
        'python_binary' => env(
            'COLOR_V8_PYTHON_BINARY',
            env('INTELLIGENCE_PYTHON_BINARY', 'python'),
        ),
        'execution_provider' => env('COLOR_V8_EXECUTION_PROVIDER', 'CPUExecutionProvider'),
        'runtime_script' => base_path('scripts/intelligence/color_v8/run_color_v8_onnx.py'),
        'image_sanitizer_script' => base_path(
            'scripts/intelligence/color_v8/sanitize_vehicle_image.py',
        ),
        'model_path' => env(
            'COLOR_V8_MODEL_PATH',
            storage_path('app/private/intelligence/models/color-v8/S7_COLOR_V8_FINAL.onnx'),
        ),
        'metadata_path' => env(
            'COLOR_V8_METADATA_PATH',
            storage_path('app/private/intelligence/models/color-v8/S7_COLOR_V8_FINAL_METADATA.json'),
        ),
        'mode' => 'consultative_only',
        'human_validation_required' => true,
        'automatic_actions_allowed' => false,
        'operational_table_writes_allowed' => false,
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
