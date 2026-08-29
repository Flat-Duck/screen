<?php

namespace App\Providers;

use App\Contracts\MediaFileStore;
use App\Contracts\PerceptualHasher;
use App\Contracts\ScreenshotSafetyAnalyzer;
use App\Contracts\ScreenshotTextExtractor;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Screenshots\DifferenceHashService;
use App\Services\Screenshots\SensitiveInformationAnalyzer;
use App\Services\Screenshots\TesseractScreenshotTextExtractor;
use App\Services\Storage\LaravelMediaFileStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MediaFileStore::class, LaravelMediaFileStore::class);
        $this->app->bind(ScreenshotTextExtractor::class, TesseractScreenshotTextExtractor::class);
        $this->app->bind(PerceptualHasher::class, DifferenceHashService::class);
        $this->app->bind(ScreenshotSafetyAnalyzer::class, SensitiveInformationAnalyzer::class);

        $this->registerTelescope();
    }

    /**
     * Telescope is a local-only debugging tool: it is a require-dev package, excluded from
     * package discovery (composer.json `extra.laravel.dont-discover`), and registered only
     * here. Production monitoring is Pulse (PulseServiceProvider) — Telescope captures full
     * request/response bodies and query bindings on every request, which is both a storage
     * and a data-exposure cost that a live deployment should not carry.
     *
     * The class_exists() guard is what makes `composer install --no-dev` safe: on a production
     * box the package is simply absent, and this becomes a no-op rather than a fatal.
     */
    protected function registerTelescope(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        if (! class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            return;
        }

        $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
        $this->app->register(TelescopeServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DevCommands::artisan('horizon', 'queue');
        $this->configureTrustedProxies();
        $this->configureDefaults();
        $this->configureMobileAuthLinks();
        $this->configureGates();
        $this->configureOperationsMonitoring();
        URL::forceScheme('https');
    }

    /** Email links land on HTTPS first, then offer the native custom-scheme handoff. */
    protected function configureMobileAuthLinks(): void
    {
        VerifyEmail::createUrlUsing(fn (User $notifiable): string => URL::temporarySignedRoute(
            'mobile.email.verify',
            now()->addMinutes(60),
            ['user' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        ));

        ResetPassword::createUrlUsing(fn (User $notifiable, string $token): string => route(
            'mobile.password.reset',
            ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()],
        ));
    }

    /**
     * Production sits behind Cloudflare *and* nginx, so without this every request reports the
     * proxy's address as the client IP. That is not cosmetic: `$request->ip()` is the throttle
     * key for `auth-register`, `auth-login`, `auth-social` and `two-factor-challenge`, the
     * fallback key for every per-user limiter, and part of Fortify's login throttle. Left
     * untrusted, all traffic collapses onto ONE key — the 10/min login limit then applies to the
     * entire internet at once, locking real users out while barely slowing a distributed
     * attacker. It also poisons the audit trail: `AuthController` stores it on the DeviceSession
     * users see on the Sessions screen, and `AdminAuditLogger` records it.
     *
     * This lives here rather than in bootstrap/app.php's `trustProxies()` for two reasons: the
     * container has no config repository that early, and `env()` there returns null once the
     * config is cached — which production always is, so the setting would silently do nothing.
     *
     * `config('app.trusted_proxies')` is a comma-separated list, or '*'. Use '*' ONLY when the
     * origin cannot be reached except through the proxy — otherwise X-Forwarded-For is
     * attacker-supplied and this becomes a throttle bypass. Behind Cloudflare that means
     * firewalling 80/443 to Cloudflare's published ranges.
     */
    protected function configureTrustedProxies(): void
    {
        $configured = config('app.trusted_proxies', '127.0.0.1');

        if (! is_string($configured) || trim($configured) === '') {
            return;
        }

        $proxies = trim($configured) === '*'
            ? '*'
            : array_values(array_filter(array_map(trim(...), explode(',', $configured))));

        Request::setTrustedProxies(
            $proxies === '*' ? ['0.0.0.0/0', '2000::/3'] : $proxies,
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    /**
     * `User` doubles as the social API's end-user principal (Sanctum, mobile app) *and*
     * the telemetry dashboard's session-auth principal (Fortify, web) — without this,
     * `auth`+`verified` alone lets any registered mobile-app user browse every device's
     * crash/event history via the web dashboard. `is_admin` is never mass-assignable
     * (deliberately absent from User's #[Fillable] attribute) — grant it only via
     * `php artisan users:make-admin {email}` or direct DB access.
     */
    protected function configureGates(): void
    {
        Gate::define('viewDashboard', fn (User $user): bool => $user->hasAdminPermission('dashboard.view'));
        Gate::define('viewOperations', fn (User $user): bool => $user->hasAdminPermission('operations.view'));
        Gate::define('viewTelemetry', fn (User $user): bool => $user->hasAdminPermission('telemetry.view'));
        Gate::define('manageTelemetry', fn (User $user): bool => $user->hasAdminPermission('telemetry.manage'));
        Gate::define('viewModeration', fn (User $user): bool => $user->hasAdminPermission('moderation.view'));
        Gate::define('manageModeration', fn (User $user): bool => $user->hasAdminPermission('moderation.manage'));
        Gate::define('viewUsers', fn (User $user): bool => $user->hasAdminPermission('users.view'));
        Gate::define('manageUserSupport', fn (User $user): bool => $user->hasAdminPermission('users.support') || $user->hasAdminPermission('moderation.manage'));
    }

    protected function configureOperationsMonitoring(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $this->recordScheduledTask($event->task->command, ['status' => 'running', 'last_started_at' => now()]);
        });
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            $this->recordScheduledTask($event->task->command, [
                'status' => 'succeeded', 'runtime_ms' => (int) round($event->runtime * 1000),
                'last_succeeded_at' => now(), 'last_error_class' => null,
            ]);
        });
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $this->recordScheduledTask($event->task->command, [
                'status' => 'failed', 'last_failed_at' => now(), 'last_error_class' => $event->exception::class,
            ]);
        });
    }

    /** @param array<string, mixed> $values */
    private function recordScheduledTask(string $command, array $values): void
    {
        ScheduledTaskRun::query()->updateOrCreate(
            ['task_key' => hash('sha256', $command)],
            ['task_name' => str($command)->after("'artisan' ")->limit(255)->toString(), ...$values],
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
