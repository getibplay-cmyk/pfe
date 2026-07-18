<?php

return [
    'scheduler' => [
        'heartbeat_component' => 'scheduler',
        'heartbeat_max_age_minutes' => max(1, (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_MINUTES', 5)),
    ],
];
