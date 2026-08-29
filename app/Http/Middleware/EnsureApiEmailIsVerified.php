<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiEmailIsVerified
{
    /**
     * Keep an unverified session useful for verification, recovery, logout, and correcting the
     * address, but prevent it from becoming a social account before email ownership is proven.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            return new JsonResponse([
                'message' => __('Please verify your email address before continuing.'),
                'code' => 'email_not_verified',
                'email_verification_required' => true,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
