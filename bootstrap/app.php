<?php

use App\Exceptions\DeviceProofOfPossessionRequired;
use App\Http\Middleware\EnsureApiEmailIsVerified;
use App\Http\Middleware\EnsureSanctumPrincipalIsDevice;
use App\Http\Middleware\EnsureSanctumPrincipalIsUser;
use App\Http\Middleware\LimitContentAnalyticsPayloadSize;
use App\Http\Middleware\LimitTelemetryPayloadSize;
use App\Http\Middleware\RecordApiRequestMetric;
use App\Http\Middleware\TouchDeviceSession;
use App\Http\Middleware\VerifyFirebaseAppCheck;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', RecordApiRequestMetric::class);
        $middleware->appendToGroup('api', VerifyFirebaseAppCheck::class);
        $middleware->alias([
            'auth.user' => EnsureSanctumPrincipalIsUser::class,
            'auth.device' => EnsureSanctumPrincipalIsDevice::class,
            'verified.email' => EnsureApiEmailIsVerified::class,
            'telemetry.size' => LimitTelemetryPayloadSize::class,
            'analytics.size' => LimitContentAnalyticsPayloadSize::class,
            'session.touch' => TouchDeviceSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DeviceProofOfPossessionRequired $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }

            return null;
        });
        // A rejected API request is invisible in production otherwise: a 422 is not an
        // exception Laravel reports, nginx's access log is root-only, and the mobile client
        // surfaces it as a bare `ApiException` with no message. That combination cost a full
        // debugging session over a failing upload whose actual cause nothing had recorded.
        //
        // Field *names* and messages only — never the submitted values, which on this API are
        // user content (screenshots, post bodies, credentials on the auth routes).
        //
        // Returning null falls through to the framework's own 422 response; this only observes.
        $exceptions->render(function (ValidationException $exception, Request $request): null {
            if ($request->is('api/*')) {
                Log::warning('API validation failed', [
                    'method' => $request->method(),
                    'route' => $request->route()?->uri(),
                    'user_id' => $request->user()?->getAuthIdentifier(),
                    'errors' => $exception->errors(),
                ]);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
