<?php

namespace App\Services\SocialAuth;

use App\Models\SocialAccount;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Verifies the OAuth access token Android obtains via Google's Authorization API
 * (`Identity.getAuthorizationClient()`) — a genuine access token, not the ID token used for the
 * local Firebase exchange (see `GoogleAuthManagerImpl` on the client). `Socialite::userFromToken()`
 * calls Google's userinfo endpoint with it as a bearer token.
 */
class GoogleTokenVerifier implements SocialTokenVerifier
{
    public function verify(string $token): SocialUserPayload
    {
        // Socialite::driver()'s return type is the generic Contracts\Provider interface, which
        // doesn't declare userFromToken() — only Two\AbstractProvider (what every concrete OAuth2
        // provider, including Google's, actually extends) does. This also catches a genuinely
        // misconfigured 'google' driver at runtime, not just a static-analysis formality.
        $provider = Socialite::driver('google');
        if (! $provider instanceof AbstractProvider) {
            throw new SocialTokenVerificationException('Google sign-in is not configured correctly.');
        }

        try {
            $user = $provider->userFromToken($token);
        } catch (Throwable $e) {
            throw new SocialTokenVerificationException('Invalid or expired token.', previous: $e);
        }

        if (empty($user->getEmail())) {
            throw new SocialTokenVerificationException('Google token has no email claim.');
        }

        $raw = $user->getRaw();

        return new SocialUserPayload(
            provider: SocialAccount::PROVIDER_GOOGLE,
            providerUserId: (string) $user->getId(),
            email: $user->getEmail(),
            emailVerified: filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN),
            name: $user->getName(),
            avatarUrl: $user->getAvatar(),
        );
    }
}
