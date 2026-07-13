<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSanctumPrincipalIsDevice
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $device = $request->user('sanctum');
        abort_unless($device instanceof Device, 403);

        foreach ($abilities as $ability) {
            abort_unless($device->tokenCan($ability), 403);
        }

        return $next($request);
    }
}
