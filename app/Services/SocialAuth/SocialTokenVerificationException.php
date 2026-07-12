<?php

namespace App\Services\SocialAuth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a provider token is invalid, expired, or fails an audience/issuer check.
 * Renders like a validation failure (422 + `errors`) to match how every other auth
 * failure in this API reports itself (see PasswordLogin's ValidationException).
 */
class SocialTokenVerificationException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['token' => [$this->getMessage()]],
        ], 422);
    }
}
