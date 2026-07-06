<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/up/deep', HealthCheckController::class)->name('health.deep');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show');

    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
});

require __DIR__.'/settings.php';
