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

        RateLimiter::for('auth-social', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('auth-password', fn (Request $request) => $this->byUser($request, 10));

        RateLimiter::for('reads', fn (Request $request) => $this->byUser($request, 60));

        // Tighter than 'reads' — a LIKE-based search is a heavier query per request than
        // a typical indexed list fetch.
        RateLimiter::for('search', fn (Request $request) => $this->byUser($request, 20));

        RateLimiter::for('writes-moderate', fn (Request $request) => $this->byUser($request, 30));

        // Looser than 'writes-moderate' — a real chat back-and-forth needs more headroom
        // than the general write-actions bucket.
        RateLimiter::for('messages-send', fn (Request $request) => $this->byUser($request, 60));

        RateLimiter::for('posts-store', fn (Request $request) => $this->byUser($request, 10));

        RateLimiter::for('reports', fn (Request $request) => $this->byUser($request, 10));

        RateLimiter::for('notifications-read', fn (Request $request) => $this->byUser($request, 60));

        RateLimiter::for('notifications-mark-all', fn (Request $request) => $this->byUser($request, 20));

        RateLimiter::for('sessions-manage', fn (Request $request) => $this->byUser($request, 20));

        RateLimiter::for('two-factor-manage', fn (Request $request) => $this->byUser($request, 20));

        // Deliberately tight — these are irreversible-feeling account actions (delete
        // account, unlink last sign-in method, change email), not routine traffic.
        RateLimiter::for('account-manage', fn (Request $request) => $this->byUser($request, 5));

        RateLimiter::for('settings-manage', fn (Request $request) => $this->byUser($request, 20));

        // IP-keyed, not user-keyed — there's no Sanctum-authenticated user yet at this
        // point in the login flow. Kept tight since this is the brute-force surface for
        // guessing a 6-digit TOTP code.
        RateLimiter::for('two-factor-challenge', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }

    /**
     * Key a limit by the authenticated user's ID, falling back to IP for unauthenticated requests.
     */
    private function byUser(Request $request, int $perMinute): Limit
    {
        return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
    }
}
