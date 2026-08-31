<?php

return [
    // off: no verification; monitor: verify and record outcomes without rejecting; enforce:
    // reject missing/invalid tokens and fail closed with 503 when verification is unavailable.
    'mode' => env('FIREBASE_APP_CHECK_MODE', 'off'),
    'project_number' => env('FIREBASE_PROJECT_NUMBER'),
    'allowed_app_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FIREBASE_APP_CHECK_ALLOWED_APP_IDS', '')),
    ))),
    'jwks_url' => env('FIREBASE_APP_CHECK_JWKS_URL', 'https://firebaseappcheck.googleapis.com/v1/jwks'),
    'jwks_cache_seconds' => (int) env('FIREBASE_APP_CHECK_JWKS_CACHE_SECONDS', 21600),
    'jwks_stale_seconds' => (int) env('FIREBASE_APP_CHECK_JWKS_STALE_SECONDS', 86400),
];
