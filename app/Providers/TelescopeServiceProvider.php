<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\EntryType;
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

        // Records everything, not just failures — this provider is only ever registered
        // in local/testing (see AppServiceProvider::registerTelescope()), where full-fidelity
        // capture is the entire point. Production monitoring is Pulse instead, which
        // aggregates rather than storing every request body and query binding.
        //
        // telescope_entries still grows continuously locally, so it relies on the daily
        // `telescope:prune` schedule (routes/console.php) to bound storage.
        Telescope::filter(function (IncomingEntry $entry): bool {
            $uri = $entry->content['uri'] ?? '';

            // A 256-bit invitation token is a bearer-like secret in this one public path.
            return ! ($entry->type === EntryType::REQUEST
                && is_string($uri)
                && preg_match('#/invite/[a-f0-9]{64}(?:\?|$)#', $uri) === 1);
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters(['_token', 'invite_token', 'invite_code', 'invite_ticket']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * Telescope only loads in local/testing now, so this gate is a defence-in-depth
     * backstop rather than the primary boundary — it mirrors `viewTelemetry` in
     * AppServiceProvider, since Telescope exposes full request/response bodies and
     * query bindings. The production equivalent is `viewPulse` (PulseServiceProvider).
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user): bool => $user->hasAdminPermission('telemetry.view'));
    }
}
