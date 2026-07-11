<?php

namespace App\Services\SocialAuth;

use App\Models\SocialAccount;

class GoogleTokenVerifier extends JwtTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    protected function issuers(): array
    {
        return ['accounts.google.com', 'https://accounts.google.com'];
    }

    protected function audience(): ?string
    {
        return config('services.google.client_id');
    }

    protected function payloadFromClaims(array $claims): SocialUserPayload
    {
        if (empty($claims['email'])) {
            throw new SocialTokenVerificationException('Google token has no email claim.');
        }

        return new SocialUserPayload(
            provider: SocialAccount::PROVIDER_GOOGLE,
            providerUserId: (string) $claims['sub'],
            email: $claims['email'],
            emailVerified: filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            name: $claims['name'] ?? null,
            avatarUrl: $claims['picture'] ?? null,
        );
    }
}
