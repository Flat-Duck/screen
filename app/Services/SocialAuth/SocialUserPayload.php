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

    /**
     * Apple's identity token never carries a name — the client sends it separately,
     * out-of-band, and only on the very first authorization — so the controller merges
     * it in after verification rather than the verifier ever knowing about it.
     */
    public function withName(?string $name): self
    {
        return new self($this->provider, $this->providerUserId, $this->email, $this->emailVerified, $name, $this->avatarUrl);
    }
}
