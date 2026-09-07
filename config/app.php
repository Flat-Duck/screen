<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Public Brand
    |--------------------------------------------------------------------------
    |
    | The product name as the public sees it. Deliberately separate from APP_NAME,
    | which is this repository's internal name and appears in the admin dashboard,
    | mail subjects and log context. The landing page and the legal documents that
    | Play Console links to must say "Akukas" regardless of what the deployment
    | calls itself.
    |
    */

    'brand' => env('APP_BRAND', 'Akukas'),

    /*
    |--------------------------------------------------------------------------
    | Google Play Listing
    |--------------------------------------------------------------------------
    |
    | Null until the listing is public. The landing page shows an unlinked
    | "coming soon" badge rather than a button that leads nowhere, so the page is
    | honest during open testing without needing a second version of itself.
    |
    */

    'play_url' => env('APP_PLAY_URL'),

    /*
    |--------------------------------------------------------------------------
    | Landing Page Photography
    |--------------------------------------------------------------------------
    |
    | Paths relative to public/. Both are optional — the hero falls back to a plain
    | disc and the closing band to its drawn ridges — so neither is a deploy blocker.
    | Configurable rather than hardcoded so the filenames can change without a code
    | edit, and so tests can point at a fixture instead of at a real asset.
    |
    */

    'landing_photos' => [
        'hero' => env('APP_LANDING_HERO_PHOTO', 'images/akakus-arch.jpg'),
        'closing' => env('APP_LANDING_CLOSING_PHOTO', 'images/akakus-panorama.jpg'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Landing Photograph Credit
    |--------------------------------------------------------------------------
    |
    | Null when the imagery needs no attribution. Set all three keys when a stock
    | licence requires a visible credit; the footer then renders it, but only on
    | pages that actually show the photograph.
    |
    */

    'landing_photo_credit' => null,

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated proxy IPs/CIDRs, or '*'. Consumed by bootstrap/app.php's
    | trustProxies() call — see the note there on why an unset value silently
    | collapses every IP-keyed rate limiter onto a single key. It lives here
    | rather than being read with env() at bootstrap because env() returns null
    | once the config is cached, which production always is.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', '127.0.0.1'),

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', '')),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
