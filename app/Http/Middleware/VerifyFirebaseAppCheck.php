<?php

namespace App\Http\Middleware;

use App\Services\AppCheck\AppCheckVerification;
use App\Services\AppCheck\FirebaseAppCheckVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyFirebaseAppCheck
{
    public function __construct(private readonly FirebaseAppCheckVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || config('app_check.mode') === 'off') {
            return $next($request);
        }

        $result = $this->verifier->verify($request->header('X-Firebase-AppCheck'));
        $request->attributes->set('app_check', $result->value);
        Log::info('Firebase App Check verification', [
            'result' => $result->value,
            'method' => $request->method(),
            'route' => $request->route()?->uri(),
        ]);

        if (config('app_check.mode') === 'monitor' || $result === AppCheckVerification::Valid) {
            return $next($request);
        }

        if ($result === AppCheckVerification::Unavailable) {
            return response()->json([
                'message' => 'App verification is temporarily unavailable. Please retry.',
                'code' => 'APP_CHECK_UNAVAILABLE',
            ], 503);
        }

        return response()->json([
            'message' => 'A valid app verification token is required.',
            'code' => 'APP_CHECK_REQUIRED',
        ], 401);
    }
}
