<?php

namespace App\Services\SocialAuth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared "fetch the provider's JWKS, verify an RS256 JWT against it" plumbing for
 * Google and Apple — the two providers whose tokens are signed JWTs, unlike Facebook's
 * opaque access token (verified via a Graph API call instead, see FacebookTokenVerifier).
 */
abstract class JwtTokenVerifier implements SocialTokenVerifier
{
    abstract protected function jwksUrl(): string;

    /** @return string[] */
    abstract protected function issuers(): array;

    abstract protected function audience(): ?string;

    /** @param array<string, mixed> $claims */
    abstract protected function payloadFromClaims(array $claims): SocialUserPayload;

    public function verify(string $token): SocialUserPayload
    {
        try {
            $claims = (array) JWT::decode($token, JWK::parseKeySet($this->jwks()));
        } catch (Throwable $e) {
            throw new SocialTokenVerificationException('Invalid or expired token.', previous: $e);
        }

        if ($this->audience() === null || ($claims['aud'] ?? null) !== $this->audience()) {
            throw new SocialTokenVerificationException('Token audience mismatch.');
        }

        if (! in_array($claims['iss'] ?? null, $this->issuers(), true)) {
            throw new SocialTokenVerificationException('Unexpected token issuer.');
        }

        return $this->payloadFromClaims($claims);
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        return Cache::remember(
            'social-auth:jwks:'.md5($this->jwksUrl()),
            now()->addHours(6),
            fn () => Http::get($this->jwksUrl())->throw()->json(),
        );
    }
}
