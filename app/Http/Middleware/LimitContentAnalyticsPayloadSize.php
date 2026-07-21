<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitContentAnalyticsPayloadSize
{
    private const MAX_BYTES = 262_144;

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = (int) $request->header('Content-Length', '0');
        if ($contentLength > self::MAX_BYTES || strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['message' => 'Content analytics payload may not exceed 256 KB.'], 413);
        }

        return $next($request);
    }
}
