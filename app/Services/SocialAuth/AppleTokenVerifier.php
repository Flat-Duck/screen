<?php

namespace App\Services\SocialAuth;

use App\Models\SocialAccount;

class AppleTokenVerifier extends JwtTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    protected function issuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    protected function audience(): ?string
    {
        return config('services.apple.client_id');
    }

    protected function payloadFromClaims(array $claims): SocialUserPayload
    {
        if (empty($claims['email'])) {
            throw new SocialTokenVerificationException('Apple token has no email claim.');
        }

        $emailVerifiedRaw = $claims['email_verified'] ?? false;

        return new SocialUserPayload(
            provider: SocialAccount::PROVIDER_APPLE,
            providerUserId: (string) $claims['sub'],
            email: $claims['email'],
            // Apple sometimes encodes this claim as the string "true"/"false" rather than a JSON boolean.
            emailVerified: is_string($emailVerifiedRaw)
                ? filter_var($emailVerifiedRaw, FILTER_VALIDATE_BOOLEAN)
                : (bool) $emailVerifiedRaw,
            // The identity token never carries a name; AuthController::apple() merges in
            // whatever the client sent out-of-band via SocialUserPayload::withName().
            name: null,
            avatarUrl: null,
        );
    }
}
