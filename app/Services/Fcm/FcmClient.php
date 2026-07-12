<?php

namespace App\Services\Fcm;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends a single push via FCM's HTTP v1 API, authenticating with a self-signed
 * service-account JWT exchanged for a short-lived OAuth2 access token — the standard
 * "server calling a Google API" auth flow, done by hand with firebase/php-jwt (already a
 * dependency, from social-login token verification) instead of pulling in the much
 * heavier kreait/laravel-firebase SDK for what's fundamentally a single HTTP call.
 */
class FcmClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** True if both FIREBASE_PROJECT_ID and a readable FIREBASE_CREDENTIALS_PATH are configured. */
    public function isConfigured(): bool
    {
        $path = config('services.fcm.credentials_path');

        return filled(config('services.fcm.project_id')) && filled($path) && is_string($path) && is_readable($path);
    }

    /**
     * @param  array<string, string>  $data  String-only key/value pairs — FCM's data
     *                                       payload requires every value to be a string.
     * @return string 'ok', 'invalid_token' (caller should stop sending to this token —
     *                it's been uninstalled/unregistered), or 'error' (transient, not the
     *                token's fault, safe to retry on the next notification).
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): string
    {
        $response = Http::withToken($this->accessToken())
            ->post(sprintf(
                'https://fcm.googleapis.com/v1/projects/%s/messages:send',
                config('services.fcm.project_id'),
            ), [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                ],
            ]);

        if ($response->successful()) {
            return 'ok';
        }

        $status = $response->json('error.status');

        if (in_array($status, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true)) {
            return 'invalid_token';
        }

        return 'error';
    }

    private function accessToken(): string
    {
        /** @var string $token */
        $token = Cache::remember('fcm:access_token', now()->addMinutes(55), function (): string {
            $credentialsPath = (string) config('services.fcm.credentials_path');

            /** @var array{client_email: string, private_key: string} $credentials */
            $credentials = json_decode(
                (string) file_get_contents($credentialsPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $now = time();

            $assertion = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])->throw();

            return (string) $response->json('access_token');
        });

        return $token;
    }
}
