<?php

namespace App\Http\Middleware;

use App\Models\DeviceSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TouchDeviceSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        $tokenId = $user->currentAccessToken()->getKey();

        // The key is not always a usable id. Sanctum::actingAs() installs a Mockery mock of
        // PersonalAccessToken built with shouldIgnoreMissing(false), so it satisfies every type
        // check while returning `false` from getKey(). Postgres rejects that outright against a
        // bigint column ("invalid input syntax for type bigint: false") rather than quietly
        // matching nothing, so it has to be filtered before the query rather than after — and
        // this middleware runs on most authenticated routes, so it takes those requests down with
        // it. MySQL and SQLite coerce instead, which is why this only ever surfaced on Postgres.
        if (! is_int($tokenId) && ! (is_string($tokenId) && ctype_digit($tokenId))) {
            return $response;
        }

        $session = DeviceSession::query()
            ->with('device')
            ->where('personal_access_token_id', $tokenId)
            ->whereNull('ended_at')
            ->first();

        if ($session && ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinutes(5)))) {
            $session->forceFill(['last_seen_at' => now()])->save();
            $session->device->forceFill(['last_seen_at' => now()])->save();
        }

        return $response;
    }
}
