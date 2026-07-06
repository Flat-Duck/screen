<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires a `* * * * * php artisan schedule:run` cron entry in the deploy environment —
// this app previously had no scheduled tasks at all.
Schedule::command('posts:prune-deleted')->daily();
