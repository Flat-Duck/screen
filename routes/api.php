<?php

use App\Http\Controllers\Api\TelemetryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('telemetry')->group(function () {
    Route::post('register', [TelemetryController::class, 'register'])
        ->middleware('throttle:20,1');

    Route::post('events', [TelemetryController::class, 'events'])
        ->middleware(['auth:sanctum', 'throttle:120,1']);
});
