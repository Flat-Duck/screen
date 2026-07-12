<?php

namespace App\Services\Auth;

/**
 * Stops a login short of issuing a token — the stateless equivalent of Fortify's own
 * session-stored "who's mid-login" state (see AuthService::twoFactorChallengeResponse()).
 */
final readonly class TwoFactorRequired
{
    public function __construct(public string $twoFactorToken) {}
}
