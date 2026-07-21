<?php

use App\Models\ApiRequestMetric;
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

Schedule::command('telemetry:prune')->daily()->onOneServer()->withoutOverlapping();

Schedule::command('analytics:prune-content-events')->daily()->onOneServer()->withoutOverlapping();

Schedule::command('analytics:aggregate --date=today')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('analytics:aggregate')->dailyAt('00:15')->onOneServer()->withoutOverlapping();

Schedule::command('sessions:expire')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

Schedule::command('operations:capture-health')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [ApiRequestMetric::class]])->daily()->onOneServer()->withoutOverlapping();

// Recomputes the trending/discovery pool FeedService blends into first-page feed loads.
// Requires Redis; safe to skip a run or have Redis blip — the published set carries its
// own safety TTL (config('social.trending.safety_ttl_minutes')) and the feed fails open.
Schedule::command('posts:refresh-trending')->everyTenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('recommendations:refresh-pools')->everyTenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('recommendations:prune-sessions')->hourly()->onOneServer()->withoutOverlapping();

// Telescope records every request/exception in every environment (see
// TelescopeServiceProvider::register()), not just failures, so telescope_entries
// grows continuously. 48h is short-lived debugging data, not the app's durable
// telemetry — that's TelemetryEvent's own TELEMETRY_RETENTION_DAYS-based pruning.
Schedule::command('telescope:prune --hours=48')->daily()->onOneServer()->withoutOverlapping();
