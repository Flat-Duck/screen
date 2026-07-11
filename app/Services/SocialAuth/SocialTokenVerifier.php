<?php

namespace App\Services\SocialAuth;

interface SocialTokenVerifier
{
    /**
     * Verifies a client-supplied provider token directly against the provider and
     * returns the normalized identity it carries.
     *
     * @throws SocialTokenVerificationException
     */
    public function verify(string $token): SocialUserPayload;
}
