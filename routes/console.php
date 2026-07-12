<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires a `* * * * * php artisan schedule:run` cron entry in the deploy environment —
// this app previously had no scheduled tasks at all.
Schedule::command('posts:prune-deleted')->daily()->onOneServer()->withoutOverlapping();

Schedule::command('users:prune-deleted')->daily()->onOneServer()->withoutOverlapping();

Schedule::command('media:clean-orphans')->everyTenMinutes()->onOneServer()->withoutOverlapping();

Schedule::command('security-outbox:dispatch')->everyMinute()->onOneServer()->withoutOverlapping();

// Recomputes the trending/discovery pool FeedService blends into first-page feed loads.
// Requires Redis; safe to skip a run or have Redis blip — the published set carries its
// own safety TTL (config('social.trending.safety_ttl_minutes')) and the feed fails open.
Schedule::command('posts:refresh-trending')->everyTenMinutes()->onOneServer()->withoutOverlapping();
