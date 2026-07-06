<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RateLimiterServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    RateLimiterServiceProvider::class,
];
