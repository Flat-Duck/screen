<?php

return [
    'outbox_stale_minutes' => (int) env('SECURITY_OUTBOX_STALE_MINUTES', 15),
    'delivery_timeout_seconds' => (int) env('SECURITY_DELIVERY_TIMEOUT_SECONDS', 30),
    'delivery_backoff_seconds' => [30, 120, 300, 900],
];
