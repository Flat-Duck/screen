<?php

namespace App\Services;

use App\Actions\Auth\CloseDeviceSession;
use App\Enums\SessionEndReason;
use App\Models\DeviceSession;
use App\Models\User;

class AuthService
{
    public function __construct(private readonly CloseDeviceSession $closeSession) {}

    /** Revokes only the current token — a login on another device stays valid. */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();
        $session = DeviceSession::query()->where('personal_access_token_id', $token->id)->first();

        if ($session) {
            ($this->closeSession)($session, SessionEndReason::Logout);

            return;
        }

        $token->delete();
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
