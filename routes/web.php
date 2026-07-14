<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmailChangeVerificationController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/up/deep', HealthCheckController::class)->name('health.deep');

// Opened from an email client (see EmailChangeService), never called by the Android app
// itself — not under /api/v1 for that reason. `signed` alone verifies the link wasn't
// tampered with; no session auth is expected or required here.
Route::get('/email/verify-change/{user}', [EmailChangeVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('email.change.verify');

Route::middleware(['auth', 'verified', 'can:viewTelemetry'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');

    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');

    Route::view('notifications', 'notifications')->name('notifications.index');
});

require __DIR__.'/settings.php';
