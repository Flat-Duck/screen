<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Locks the production/dev split between the two monitoring surfaces.
 *
 * Pulse ships to production and is admin-gated; Telescope is require-dev and must never
 * be registered outside local/testing. A regression in either direction is silent and
 * exposes slow-query SQL, exception locations, and per-user activity to any logged-in
 * mobile-app user — `User` is both the API principal and the web dashboard principal.
 */
class MonitoringAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pulse_gate_denies_guests(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('viewPulse'));
    }

    public function test_pulse_gate_denies_non_admin_users(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewPulse'));
    }

    public function test_pulse_gate_allows_admin_users(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->assertTrue(Gate::forUser($admin)->allows('viewPulse'));
    }

    public function test_pulse_dashboard_is_forbidden_for_non_admin_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/pulse')->assertForbidden();
    }

    public function test_pulse_dashboard_is_reachable_by_admin_users(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/pulse')->assertOk();
    }

    /**
     * The guard that makes `composer install --no-dev` safe: Telescope's provider is
     * registered only by AppServiceProvider::registerTelescope(), which returns early
     * outside local/testing. If this ever fails, Telescope is shipping to production.
     */
    public function test_telescope_is_not_registered_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $provider = new AppServiceProvider($this->app);
        $provider->register();

        $routes = collect($this->app['router']->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->uri(), 'telescope'));

        $this->assertTrue($routes->isEmpty(), 'Telescope routes must not be registered in production.');
    }
}
