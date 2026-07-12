<?php

namespace App\Services;

use App\Models\User;

class AuthService
{
    /** Revokes only the current token — a login on another device stays valid. */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /**
     * Sets a password on an account that may not have had one before (a social-only
     * sign-up completing profile setup) or is changing an existing one — the caller
     * (SetPasswordRequest) has already confirmed `current_password` when one exists.
     */
    public function setPassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->save();
    }
}
