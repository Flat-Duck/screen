<?php

namespace App\Services\AppCheck;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class FirebaseAppCheckVerifier
{
    public function verify(?string $token): AppCheckVerification
    {
        if ($token === null || trim($token) === '') {
            return AppCheckVerification::Missing;
        }

        $projectNumber = (string) config('app_check.project_number');
        if ($projectNumber === '') {
            return AppCheckVerification::Unavailable;
        }

        try {
            $header = $this->decodeSegment(explode('.', $token)[0]);
            if (($header['alg'] ?? null) !== 'RS256' || ($header['typ'] ?? null) !== 'JWT') {
                return AppCheckVerification::Invalid;
            }

            $keys = JWK::parseKeySet($this->keySet());
            $claims = (array) JWT::decode($token, $keys);
            $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
            $allowedAppIds = config('app_check.allowed_app_ids', []);

            if (($claims['iss'] ?? null) !== "https://firebaseappcheck.googleapis.com/{$projectNumber}"
                || ! in_array("projects/{$projectNumber}", $audiences, true)
                || ! is_string($claims['sub'] ?? null)
                || ($allowedAppIds !== [] && ! in_array($claims['sub'], $allowedAppIds, true))) {
                return AppCheckVerification::Invalid;
            }

            return AppCheckVerification::Valid;
        } catch (AppCheckVerificationUnavailable) {
            return AppCheckVerification::Unavailable;
        } catch (Throwable) {
            return AppCheckVerification::Invalid;
        }
    }

    /** @return array<string, mixed> */
    private function keySet(): array
    {
        $cacheKey = 'firebase-app-check:jwks:current';
        $staleKey = 'firebase-app-check:jwks:stale';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $keys = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->get((string) config('app_check.jwks_url'))
                ->throw()
                ->json();
            if (! is_array($keys) || ! is_array($keys['keys'] ?? null) || $keys['keys'] === []) {
                throw new AppCheckVerificationUnavailable;
            }
            Cache::put($cacheKey, $keys, (int) config('app_check.jwks_cache_seconds'));
            Cache::put($staleKey, $keys, (int) config('app_check.jwks_stale_seconds'));

            return $keys;
        } catch (Throwable $exception) {
            $stale = Cache::get($staleKey);
            if (is_array($stale)) {
                return $stale;
            }

            throw new AppCheckVerificationUnavailable(previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decodeSegment(string $segment): array
    {
        $base64 = strtr($segment, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new JsonException('Invalid JWT header encoding.');
        }

        return json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
    }
}

class AppCheckVerificationUnavailable extends \RuntimeException {}
