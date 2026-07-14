<?php

return [
    'disk' => 'local',
    'max_size_kb' => (int) env('PRIVATE_DOCUMENT_MAX_KB', 10240),
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
];
