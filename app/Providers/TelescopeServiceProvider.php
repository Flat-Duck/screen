<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        // Records everything (not just failures) in every environment, per the
        // explicit call to monitor crashes *and* requests in production — this
        // means telescope_entries grows continuously, so it relies on the daily
        // `telescope:prune` schedule (routes/console.php) to bound storage.
        Telescope::filter(fn (IncomingEntry $entry): bool => true);
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     * Mirrors `viewTelemetry` in AppServiceProvider — same admin-only boundary,
     * since Telescope exposes full request/response bodies and query bindings.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user): bool => $user->hasAdminPermission('telemetry.view'));
    }
}
