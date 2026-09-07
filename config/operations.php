<?php

return [
    'scheduler' => [
        'heartbeat_component' => 'scheduler',
        'heartbeat_max_age_minutes' => max(1, (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_MINUTES', 5)),
    ],
    'monitoring' => [
        'heartbeat_component' => 'platform-monitor',
        'queue_backlog_max' => max(0, (int) env('OPERATIONS_QUEUE_BACKLOG_MAX', 100)),
        'queue_stale_minutes' => max(1, (int) env('OPERATIONS_QUEUE_STALE_MINUTES', 10)),
        'failed_jobs_max' => max(0, (int) env('OPERATIONS_FAILED_JOBS_MAX', 0)),
        'cmi_failure_lookback_minutes' => max(5, (int) env('OPERATIONS_CMI_FAILURE_LOOKBACK_MINUTES', 60)),
        'cmi_failures_max' => max(0, (int) env('OPERATIONS_CMI_FAILURES_MAX', 3)),
        'disk_free_min_percent' => min(99, max(1, (int) env('OPERATIONS_DISK_FREE_MIN_PERCENT', 15))),
        'disk_path' => storage_path(),
    ],
];
