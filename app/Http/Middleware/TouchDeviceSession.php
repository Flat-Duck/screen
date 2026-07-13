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
        $token = $user->currentAccessToken();

        $session = DeviceSession::query()
            ->with('device')
            ->where('personal_access_token_id', $token->id)
            ->whereNull('ended_at')
            ->first();

        if ($session && ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinutes(5)))) {
            $session->forceFill(['last_seen_at' => now()])->save();
            $session->device->forceFill(['last_seen_at' => now()])->save();
        }

        return $response;
    }
}
