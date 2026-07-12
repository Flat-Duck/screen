<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\IssuedAccessToken;

class IssueAccessToken
{
    public function __invoke(User $user, string $deviceName, bool $isNewAccount = false): IssuedAccessToken
    {
        return new IssuedAccessToken(
            user: $user,
            token: $user->createToken($deviceName)->plainTextToken,
            isNewAccount: $isNewAccount,
        );
    }
}
