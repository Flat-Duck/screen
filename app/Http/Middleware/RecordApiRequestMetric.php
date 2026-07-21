<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordApiRequestMetric
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);
        $duration = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
        $minute = now('UTC')->startOfMinute();

        try {
            DB::transaction(function () use ($minute, $response, $duration): void {
                DB::table('api_request_metrics')->insertOrIgnore([
                    'minute' => $minute,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row = DB::table('api_request_metrics')->where('minute', $minute)->lockForUpdate()->first();
                DB::table('api_request_metrics')->where('minute', $minute)->update([
                    'requests' => (int) $row->requests + 1,
                    'errors' => (int) $row->errors + ($response->getStatusCode() >= 500 ? 1 : 0),
                    'rate_limited' => (int) $row->rate_limited + ($response->getStatusCode() === 429 ? 1 : 0),
                    'total_duration_ms' => (int) $row->total_duration_ms + $duration,
                    'max_duration_ms' => max((int) $row->max_duration_ms, $duration),
                    'updated_at' => now(),
                ]);
            });
        } catch (Throwable) {
            // Monitoring must never turn an otherwise successful API response into a failure.
        }

        return $response;
    }
}
