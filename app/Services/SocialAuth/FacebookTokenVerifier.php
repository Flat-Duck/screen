<?php

namespace App\Services\SocialAuth;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

class FacebookTokenVerifier implements SocialTokenVerifier
{
    public function verify(string $token): SocialUserPayload
    {
        $this->assertTokenBelongsToThisApp($token);

        $profile = Http::get('https://graph.facebook.com/me', [
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $token,
        ]);

        if ($profile->failed()) {
            throw new SocialTokenVerificationException('Invalid or expired Facebook access token.');
        }

        $data = $profile->json();

        if (empty($data['email'])) {
            throw new SocialTokenVerificationException('Facebook account has no email available.');
        }

        return new SocialUserPayload(
            provider: SocialAccount::PROVIDER_FACEBOOK,
            providerUserId: (string) $data['id'],
            email: $data['email'],
            emailVerified: true, // Meta only ever returns emails it has already confirmed
            name: $data['name'] ?? null,
            avatarUrl: $data['picture']['data']['url'] ?? null,
        );
    }

    /**
     * Confirms the token is valid and was minted for *this* app, not some other app
     * that happens to also use Facebook Login — otherwise any valid Facebook token
     * from anywhere would be accepted.
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
