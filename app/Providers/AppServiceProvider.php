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
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Foundation\DevCommands;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DevCommands::artisan('horizon', 'queue');
        $this->configureDefaults();
        $this->configureGates();
        $this->configureOperationsMonitoring();
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
