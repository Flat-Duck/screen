<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `personal_access_tokens` is polymorphic, so a valid Device token (issued by the telemetry
 * endpoints) would also pass `auth:sanctum` on the social v1 routes, resolving $request->user()
 * to a Device instead of a User — a real IDOR risk if a controller blindly trusted that ID.
 * This middleware closes that gap on every v1 route that requires a human user principal.
 */
class EnsureSanctumPrincipalIsUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() instanceof User, 403);

        return $next($request);
    }
}
