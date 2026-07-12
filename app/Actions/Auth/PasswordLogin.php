<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use App\Services\Auth\TwoFactorRequired;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordLogin
{
    public function __construct(
        private readonly IssueAccessToken $issueToken,
        private readonly BeginTwoFactorChallenge $beginTwoFactor,
    ) {}

    public function __invoke(string $login, string $password, string $deviceName): IssuedAccessToken|TwoFactorRequired
    {
        $user = User::query()->where('email', $login)->orWhere('username', $login)->first();

        if ($user && $user->password === null) {
            throw ValidationException::withMessages([
                'login' => __('This account signs in with Google, Facebook, or Apple. Continue with one of those, or set a password from your profile first.'),
            ]);
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['login' => __('auth.failed')]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return ($this->beginTwoFactor)($user);
        }

        return ($this->issueToken)($user, $deviceName);
    }
}
