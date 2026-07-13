<?php

namespace App\Actions\Auth;

use App\Enums\SessionEndReason;
use App\Models\User;

final class RevokeUserSessions
{
    public function __construct(private readonly CloseDeviceSession $closeSession) {}

    public function __invoke(User $user, SessionEndReason $reason, ?int $exceptSessionId = null): void
    {
        $query = $user->deviceSessions()->whereNull('ended_at');

        if ($exceptSessionId !== null) {
            $query->whereKeyNot($exceptSessionId);
        }

        foreach ($query->get() as $session) {
            ($this->closeSession)($session, $reason);
        }

        // Also remove tokens issued by web/Fortify flows that have no device session.
        $user->tokens()->when(
            $exceptSessionId !== null,
            fn ($tokens) => $tokens->whereNotIn('id', $user->deviceSessions()->whereKey($exceptSessionId)->pluck('personal_access_token_id')),
        )->delete();
    }
}
