<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Pulse is the *production* monitoring surface (Telescope is dev-only — see
 * `bootstrap/providers.php`). It records request/exception/queue/slow-query
 * aggregates continuously and cheaply, which is what a live deployment needs,
 * rather than Telescope's full per-request payload capture.
 */
class PulseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureGate();
    }

    /**
     * Register the Pulse gate.
     *
     * Mirrors `viewHorizon`/`viewTelescope` — same admin-only boundary, since Pulse
     * exposes slow queries (including bindings-adjacent SQL), exception messages and
     * locations, and per-user activity for the same devices/users the telemetry
     * dashboard covers. `?User` because Pulse evaluates this for guests too.
     */
    protected function configureGate(): void
    {
        Gate::define('viewPulse', fn (?User $user): bool => $user?->hasAdminPermission('telemetry.view') ?? false);
    }
}
