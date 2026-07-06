<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimiterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureV1ApiRateLimiting();
    }

    /**
     * Named rate limiters for the /api/v1/* social API, keyed by concern rather than
     * HTTP verb so routes/api_v1.php stays readable as it grows.
     */
    private function configureV1ApiRateLimiting(): void
    {
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('auth-logout', fn (Request $request) => $this->byUser($request, 30));

        RateLimiter::for('reads', fn (Request $request) => $this->byUser($request, 60));

        RateLimiter::for('writes-moderate', fn (Request $request) => $this->byUser($request, 30));

        RateLimiter::for('posts-store', fn (Request $request) => $this->byUser($request, 10));

        RateLimiter::for('reports', fn (Request $request) => $this->byUser($request, 10));

        RateLimiter::for('notifications-read', fn (Request $request) => $this->byUser($request, 60));

        RateLimiter::for('notifications-mark-all', fn (Request $request) => $this->byUser($request, 20));
    }

    /**
     * Key a limit by the authenticated user's ID, falling back to IP for unauthenticated requests.
     */
    private function byUser(Request $request, int $perMinute): Limit
    {
        return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
    }
}
