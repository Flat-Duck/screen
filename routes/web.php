<?php

use App\Http\Controllers\AdminPostMediaController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\CrashGroupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmailChangeVerificationController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ExperimentStatusController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ModerationCaseController;
use App\Http\Controllers\OperationsDashboardController;
use App\Http\Controllers\RecommendationAdminController;
use App\Http\Middleware\PreventSensitivePageCaching;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/up/deep', HealthCheckController::class)->name('health.deep');

// Opened from an email client (see EmailChangeService), never called by the Android app
// itself — not under /api/v1 for that reason. `signed` alone verifies the link wasn't
// tampered with; no session auth is expected or required here.
Route::get('/email/verify-change/{user}', [EmailChangeVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('email.change.verify');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('can:viewDashboard')->name('dashboard');
    Route::get('operations', OperationsDashboardController::class)->middleware(['can:viewOperations', PreventSensitivePageCaching::class])->name('operations.index');
    Route::get('experiments', ExperimentStatusController::class)->middleware('can:viewDashboard')->name('experiments.index');
    Route::get('recommendations', [RecommendationAdminController::class, 'index'])->middleware(['can:viewModeration', PreventSensitivePageCaching::class])->name('recommendations.index');
    Route::post('recommendations/posts/{post}/exclude', [RecommendationAdminController::class, 'exclude'])->middleware('can:manageModeration')->name('recommendations.exclude');
    Route::delete('recommendations/exclusions/{exclusion}', [RecommendationAdminController::class, 'restore'])->middleware('can:manageModeration')->name('recommendations.restore');
    Route::post('recommendations/serving', [RecommendationAdminController::class, 'serving'])->middleware('can:manageModeration')->name('recommendations.serving');

    Route::middleware('can:viewTelemetry')->group(function () {
        Route::get('crash-groups', [CrashGroupController::class, 'index'])->name('crash-groups.index');
        Route::get('crash-groups/{group}', [CrashGroupController::class, 'show'])->middleware(PreventSensitivePageCaching::class)->name('crash-groups.show');
        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');

        Route::get('events', [EventController::class, 'index'])->name('events.index');
        Route::get('events/{event}', [EventController::class, 'show'])->middleware(PreventSensitivePageCaching::class)->name('events.show');

        Route::view('notifications', 'notifications')->name('notifications.index');
    });

    Route::view('users', 'users')->middleware('can:viewUsers')->name('users.index');
    Route::get('users/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->middleware(['can:viewUsers', PreventSensitivePageCaching::class])->name('users.show');

    Route::view('reports', 'reports')->middleware('can:viewModeration')->name('reports.index');

    Route::middleware(['can:viewModeration', PreventSensitivePageCaching::class])->prefix('moderation')->name('moderation.')->group(function () {
        Route::get('cases', [ModerationCaseController::class, 'index'])->name('cases.index');
        Route::get('cases/{case}', [ModerationCaseController::class, 'show'])->name('cases.show');
        Route::get('content', [ContentController::class, 'index'])->name('content.index');
        Route::get('content/{post}', [ContentController::class, 'show'])->whereNumber('post')->name('content.show');
        Route::get('media/{media}', AdminPostMediaController::class)->name('media.show');
    });
});

require __DIR__.'/settings.php';
