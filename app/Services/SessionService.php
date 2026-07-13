<?php

namespace App\Services;

use App\Actions\Auth\CloseDeviceSession;
use App\Actions\Auth\RevokeUserSessions;
use App\Enums\SessionEndReason;
use App\Models\DeviceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class SessionService
{
    public function __construct(
        private readonly CloseDeviceSession $closeSession,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    /**
     * @return Collection<int, DeviceSession>
     */
    public function listFor(User $user): Collection
    {
        return $user->deviceSessions()->with('device')->latest('started_at')->get();
    }

    /** Scoped to the user's sessions and addressed only by its public UUID. */
    public function revoke(User $user, string $sessionUuid): void
    {
        $session = $user->deviceSessions()->where('uuid', $sessionUuid)->first();

        if ($session) {
            ($this->closeSession)($session, SessionEndReason::Revoked);
        }
    }

    /** Leaves the token used for this very request untouched. */
    public function revokeOthers(User $user, int $currentSessionId): void
    {
        ($this->revokeSessions)($user, SessionEndReason::Revoked, $currentSessionId);
    }
}
