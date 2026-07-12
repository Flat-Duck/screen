<?php

use App\Http\Controllers\Api\TelemetryController;
use Illuminate\Support\Facades\Route;

// Deliberately no generic `/user` endpoint here: personal_access_tokens is polymorphic
// (Device or User), and a route that just echoes back whichever principal a token
// resolves to — without going through the v1 UserResource/auth.user guard — bypasses
// every field-visibility rule the rest of the API enforces. `GET /v1/users/{user}` (via
// UserController, behind auth.user) is the correct equivalent for a User principal;
// there's no equivalent need for a Device principal to fetch "itself".

Route::prefix('telemetry')->group(function () {
    Route::post('register', [TelemetryController::class, 'register'])
        ->middleware('throttle:20,1');

    Route::post('events', [TelemetryController::class, 'events'])
        ->middleware(['auth:sanctum', 'throttle:120,1']);
});

Route::prefix('v1')->group(base_path('routes/api_v1.php'));
