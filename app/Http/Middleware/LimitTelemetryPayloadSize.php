<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitTelemetryPayloadSize
{
    private const MAX_BYTES = 524_288;

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['message' => 'Telemetry payload may not exceed 512 KB.'], 413);
        }

        return $next($request);
    }
}
