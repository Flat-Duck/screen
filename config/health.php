<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deep Health Check Secret
    |--------------------------------------------------------------------------
    |
    | `/up/deep` (HealthCheckController) probes real dependencies and returns raw
    | exception messages plus the queue backlog count — useful for an uptime monitor,
    | but not safe to leave public: it's unauthenticated by necessity (an uptime
    | monitor has no user session), so a shared secret sent as a header is the gate
    | instead. Unset (empty) means the endpoint fails closed (404) for everyone,
    | including in an environment that forgot to configure it — never silently public.
    |
    */

    'deep_check_secret' => env('HEALTH_CHECK_SECRET'),

];
