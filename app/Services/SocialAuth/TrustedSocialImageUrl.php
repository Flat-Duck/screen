<?php

namespace App\Services\SocialAuth;

use App\Exceptions\PermanentRemoteImageException;

/** A validated provider-host URL accepted by the server-side social avatar importer. */
final readonly class TrustedSocialImageUrl
{
    private function __construct(public string $url, public string $host) {}

    public static function from(string $url): self
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new PermanentRemoteImageException('Remote image URL is malformed.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $rawHost = (string) ($parts['host'] ?? '');
        $host = strtolower($rawHost);
        $port = $parts['port'] ?? null;
        if ($scheme !== 'https' || $host === '' || $rawHost !== $host || ! self::hasValidDnsLabels($host) || isset($parts['user']) || isset($parts['pass']) ||
            ($port !== null && $port !== 443)) {
            throw new PermanentRemoteImageException('Remote image URL is not an allowed HTTPS address.');
        }

        foreach (config('social.images.remote_avatar_allowed_domains', []) as $domain) {
            $domain = strtolower(ltrim((string) $domain, '.'));
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return new self($url, $host);
            }
        }

        throw new PermanentRemoteImageException('Remote image host is not an allowed social-provider domain.');
    }

    private static function hasValidDnsLabels(string $host): bool
    {
        return preg_match(
            '/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D',
            $host,
        ) === 1;
    }
}
