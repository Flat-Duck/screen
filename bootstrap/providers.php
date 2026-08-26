<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PulseServiceProvider;
use App\Providers\RateLimiterServiceProvider;

// Telescope is deliberately absent here. It is a require-dev package (and
// `dont-discover`ed in composer.json), registered only in local/testing by
// AppServiceProvider::register() — so a production `composer install --no-dev`
// never loads it. Pulse is the production monitoring surface instead.
return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    PulseServiceProvider::class,
    RateLimiterServiceProvider::class,
];
