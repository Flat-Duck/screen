<?php

return [
    'outbox_stale_minutes' => (int) env('SECURITY_OUTBOX_STALE_MINUTES', 15),
    'delivery_timeout_seconds' => (int) env('SECURITY_DELIVERY_TIMEOUT_SECONDS', 30),
    'delivery_backoff_seconds' => [30, 120, 300, 900],
    'user_session_lifetime_days' => (int) env('USER_SESSION_LIFETIME_DAYS', 90),
];
