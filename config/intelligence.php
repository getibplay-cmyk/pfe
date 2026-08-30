<?php

$damageBackend = env('DAMAGE_V1_BACKEND', 'rtdetrv2_s');
$damageBackend = in_array($damageBackend, ['rtdetrv2_s', 'efficientnetv2s'], true)
    ? $damageBackend
    : 'rtdetrv2_s';

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

    'rental_usage_anomaly' => [
        'enabled' => env('RENTFLEET_ANOMALY_V1_ENABLED', false),
        'runtime_queue' => 'intelligence',
        'runtime_timeout_seconds' => 60,
        'runtime_stale_after_seconds' => 600,
        'rate_limits' => [
            'user_per_minute' => env('ANOMALY_V1_USER_RATE_LIMIT_PER_MINUTE', 2),
            'scope_per_hour' => env('ANOMALY_V1_SCOPE_RATE_LIMIT_PER_HOUR', 10),
        ],
        'python_binary' => env(
            'ANOMALY_V1_PYTHON_BINARY',
            env('INTELLIGENCE_PYTHON_BINARY', 'python'),
        ),
        'runtime_script' => base_path(
            'scripts/intelligence/rental_usage_anomaly/run_rental_usage_anomaly.py',
        ),
        'minimum_rows' => 200,
        'default_budget_basis_points' => 100,
        'budgets_basis_points' => [50, 100, 200],
        'mode' => 'consultative_only',
        'human_validation_required' => true,
        'automatic_actions_allowed' => false,
        'operational_table_writes_allowed' => false,
        'decision_effect' => 'NO_OPERATIONAL_ACTION',
    ],

    'demand_forecasting' => [
        'disk' => 'local',
        'max_upload_kilobytes' => 512,
        'runtime_enabled' => env('DEMAND_FORECAST_RUNTIME_ENABLED', true),
        'runtime_queue' => 'intelligence',
        'runtime_timeout_seconds' => 60,
        'runtime_stale_after_seconds' => 600,
        'rate_limits' => [
            'user_per_minute' => 2,
            'scope_per_hour' => 10,
        ],
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
        'disk' => 'intelligence-private',
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

    'vehicle_damage_v1' => [
        'enabled' => env('RENTFLEET_DAMAGE_V1_ENABLED', false),
        'backend' => $damageBackend,
        'disk' => 'intelligence-private',
        'runtime_queue' => 'intelligence',
        'runtime_timeout_seconds' => 120,
        'runtime_stale_after_seconds' => 900,
        'image_sanitizer_timeout_seconds' => 15,
        'max_upload_kilobytes' => 8192,
        'max_image_dimension' => 8000,
        'max_stored_image_dimension' => 2048,
        'max_scan_patches' => $damageBackend === 'rtdetrv2_s' ? 1 : 36,
        'rate_limits' => [
            'user_per_minute' => env('DAMAGE_V1_USER_RATE_LIMIT_PER_MINUTE', 3),
            'scope_per_hour' => env('DAMAGE_V1_SCOPE_RATE_LIMIT_PER_HOUR', 20),
        ],
        'python_binary' => env(
            'DAMAGE_V1_PYTHON_BINARY',
            env('INTELLIGENCE_PYTHON_BINARY', 'python'),
        ),
        'execution_provider' => env('DAMAGE_V1_EXECUTION_PROVIDER', 'CPUExecutionProvider'),
        'runtime_script' => base_path($damageBackend === 'rtdetrv2_s'
            ? 'scripts/intelligence/vehicle_damage/run_vehicle_damage_rtdetrv2_onnx.py'
            : 'scripts/intelligence/vehicle_damage/run_vehicle_damage_onnx.py'),
        'image_sanitizer_script' => base_path(
            'scripts/intelligence/vehicle_damage/sanitize_return_image.py',
        ),
        'model_path' => env(
            'DAMAGE_V1_MODEL_PATH',
            storage_path($damageBackend === 'rtdetrv2_s'
                ? 'app/private/intelligence/models/vehicle-damage-rtdetrv2-s/model.onnx'
                : 'app/private/intelligence/models/vehicle-damage-v1/model.onnx'),
        ),
        'model_card_path' => env(
            'DAMAGE_V1_MODEL_CARD_PATH',
            storage_path($damageBackend === 'rtdetrv2_s'
                ? 'app/private/intelligence/models/vehicle-damage-rtdetrv2-s/model_card.json'
                : 'app/private/intelligence/models/vehicle-damage-v1/model_card.json'),
        ),
        'model_sha256' => env('DAMAGE_V1_MODEL_SHA256'),
        'model_card_sha256' => env('DAMAGE_V1_MODEL_CARD_SHA256'),
        'mode' => 'consultative_only',
        'human_validation_required' => true,
        'automatic_actions_allowed' => false,
        'operational_table_writes_allowed' => false,
        'local_pilot_required' => true,
        'decision_effect' => 'NO_OPERATIONAL_ACTION',
    ],

    'vehicle_plate_hybrid_review' => [
        // The runtime remains disabled until the private corrected pilot and
        // the preregistered release gate are complete. The contract can be
        // integrated and tested without activating a business action.
        'enabled' => env('RENTFLEET_PLATE_HYBRID_REVIEW_ENABLED', false),
        'disk' => 'intelligence-private',
        'runtime_queue' => 'intelligence',
        'runtime_timeout_seconds' => 120,
        'runtime_stale_after_seconds' => 900,
        'image_sanitizer_timeout_seconds' => 15,
        'max_upload_kilobytes' => 8192,
        'max_image_dimension' => 8000,
        'max_stored_image_dimension' => 2048,
        'rate_limits' => [
            'user_per_minute' => env('PLATE_HYBRID_USER_RATE_LIMIT_PER_MINUTE', 5),
            'scope_per_hour' => env('PLATE_HYBRID_SCOPE_RATE_LIMIT_PER_HOUR', 30),
        ],
        'python_binary' => env(
            'PLATE_HYBRID_PYTHON_BINARY',
            env('INTELLIGENCE_PYTHON_BINARY', 'python'),
        ),
        'device' => env('PLATE_HYBRID_DEVICE', 'cpu'),
        'runtime_script' => base_path(
            'scripts/intelligence/vehicle_plate/hybrid_ocr_worker.py',
        ),
        // Detection and OCR intentionally use separate Python environments.
        // The private checkpoint path and digest are supplied only at runtime.
        'detector' => [
            'python_binary' => env('PLATE_DETECTOR_PYTHON_BINARY', 'python'),
            'device' => env('PLATE_DETECTOR_DEVICE', 'cpu'),
            'timeout_seconds' => (int) env('PLATE_DETECTOR_TIMEOUT_SECONDS', 180),
            'threshold' => (float) env('PLATE_DETECTOR_THRESHOLD', 0.075),
            'crop_padding_ratio' => (float) env('PLATE_DETECTOR_CROP_PADDING_RATIO', 0.04),
            'runtime_script' => base_path(
                'scripts/intelligence/vehicle_plate/plate_detector_worker.py',
            ),
            'model_path' => env(
                'PLATE_DETECTOR_MODEL_PATH',
                storage_path(
                    'app/private/intelligence/models/vehicle-plate/detector_e32_selected.pt',
                ),
            ),
            'model_sha256' => env('PLATE_DETECTOR_MODEL_SHA256'),
        ],
        'image_sanitizer_script' => base_path(
            'scripts/intelligence/color_v8/sanitize_vehicle_image.py',
        ),
        'mode' => 'consultative_review_only',
        'human_validation_required' => true,
        'automatic_actions_allowed' => false,
        'operational_table_writes_allowed' => false,
        'correction_capture_allowed' => true,
        'daily_feedback_capture_allowed' => true,
        'automatic_daily_retraining_allowed' => false,
        'release_gate_required' => true,
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
