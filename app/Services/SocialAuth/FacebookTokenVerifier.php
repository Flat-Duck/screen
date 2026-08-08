<?php

namespace App\Services\SocialAuth;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

class FacebookTokenVerifier implements SocialTokenVerifier
{
    public function verify(string $token): SocialUserPayload
    {
        $this->assertTokenBelongsToThisApp($token);

        // See GoogleTokenVerifier's matching check for why this instanceof narrowing is needed —
        // Socialite::driver()'s declared return type doesn't include userFromToken().
        $provider = Socialite::driver('facebook');
        if (! $provider instanceof AbstractProvider) {
            throw new SocialTokenVerificationException('Facebook sign-in is not configured correctly.');
        }

        try {
            $user = $provider->userFromToken($token);
        } catch (Throwable $e) {
            throw new SocialTokenVerificationException('Invalid or expired Facebook access token.', previous: $e);
        }

        if (empty($user->getEmail())) {
            throw new SocialTokenVerificationException('Facebook account has no email available.');
        }

        return new SocialUserPayload(
            provider: SocialAccount::PROVIDER_FACEBOOK,
            providerUserId: (string) $user->getId(),
            email: $user->getEmail(),
            emailVerified: true, // Meta only ever returns emails it has already confirmed
            name: $user->getName(),
            avatarUrl: $user->getAvatar(),
        );
    }

    /**
     * Confirms the token is valid and was minted for *this* app, not some other app
     * that happens to also use Facebook Login — otherwise any valid Facebook token
     * from anywhere would be accepted. Socialite's own access-token path adds an
     * `appsecret_proof` when a client secret is configured, but that only stops a stolen
     * token being replayed by someone who doesn't know our secret — it doesn't confirm the
     * token itself was issued to our app, which is what this explicit check is for.
     */
    private function assertTokenBelongsToThisApp(string $token): void
    {
        $appToken = config('services.facebook.app_id').'|'.config('services.facebook.app_secret');

        $debug = Http::get('https://graph.facebook.com/debug_token', [
            'input_token' => $token,
            'access_token' => $appToken,
        ]);

        $isValid = $debug->ok() && (bool) $debug->json('data.is_valid');
        $matchesApp = $debug->json('data.app_id') === (string) config('services.facebook.app_id');

        if (! $isValid || ! $matchesApp) {
            throw new SocialTokenVerificationException('Invalid or expired Facebook access token.');
        }
    }
}
