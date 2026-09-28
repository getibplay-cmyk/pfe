<?php

return [
    'mfa_require_admins' => env('SECURITY_MFA_REQUIRE_ADMINS', env('APP_ENV') === 'production'),
    'session_absolute_minutes' => (int) env('SECURITY_SESSION_ABSOLUTE_MINUTES', 480),
    'password_breach_check' => env('SECURITY_PASSWORD_BREACH_CHECK', env('APP_ENV') === 'production'),
    'authentication' => ['global_per_minute' => (int) env('SECURITY_AUTH_GLOBAL_PER_MINUTE', 600)],
    'intelligence' => [
        'global_pending' => (int) env('SECURITY_AI_GLOBAL_PENDING', 100),
        'tenant_pending' => (int) env('SECURITY_AI_TENANT_PENDING', 10),
    ],
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'uploads' => [
        'scan_required' => env('SECURITY_UPLOAD_SCAN_REQUIRED', env('APP_ENV') === 'production'),
        'clamd_endpoint' => env('SECURITY_CLAMD_ENDPOINT', ''),
        'scan_timeout_seconds' => 10,
        'max_bytes' => 10_485_760,
        'max_pixels' => 16_000_000,
    ],
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ],

    'hsts' => [
        'enabled' => env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),
    ],
];
