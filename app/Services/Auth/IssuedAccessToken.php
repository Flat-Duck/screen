<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Together with {@see TwoFactorRequired}, replaces the old
 * `array{user, token}|array{requires_two_factor, two_factor_token}` return-shape union
 * on AuthService/SocialAuthService's login-shaped methods — a plain `IssuedAccessToken|
 * TwoFactorRequired` union type instead of a marker interface, so `instanceof` checks
 * at call sites narrow correctly under static analysis (an interface with unknown/open
 * implementors doesn't).
 */
final readonly class IssuedAccessToken
{
    public function __construct(
        public User $user,
        public string $token,
        public bool $isNewAccount = false,
    ) {}
}
