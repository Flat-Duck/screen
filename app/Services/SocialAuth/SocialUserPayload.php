<?php

namespace App\Services\SocialAuth;

/** A normalized identity extracted from a verified provider token. */
final readonly class SocialUserPayload
{
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public string $email,
        public bool $emailVerified,
        public ?string $name,
        public ?string $avatarUrl,
    ) {}
}
