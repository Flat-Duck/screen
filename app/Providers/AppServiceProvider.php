<?php

namespace App\Providers;

use App\Contracts\MediaFileStore;
use App\Models\User;
use App\Services\Storage\LaravelMediaFileStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DevCommands::artisan('horizon', 'queue');
        $this->configureDefaults();
        $this->configureGates();
        URL::forceScheme('https');
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
        Gate::define('viewTelemetry', fn (User $user): bool => $user->hasAdminPermission('telemetry.view'));
        Gate::define('viewModeration', fn (User $user): bool => $user->hasAdminPermission('moderation.view'));
        Gate::define('manageModeration', fn (User $user): bool => $user->hasAdminPermission('moderation.manage'));
        Gate::define('viewUsers', fn (User $user): bool => $user->hasAdminPermission('users.view'));
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
