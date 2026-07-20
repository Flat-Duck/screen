<?php

namespace App\Actions\Accounts;

use App\Actions\Auth\RevokeUserSessions;
use App\Enums\SessionEndReason;
use App\Enums\UserModerationState;
use App\Enums\UserVisibilityState;
use App\Models\User;
use App\Services\AccountService;

/**
 * An admin kill-switch on login, distinct from {@see AccountService::deleteAccount()}
 * — deactivating a user blocks new sessions (see `StartDeviceSession`) and revokes any
 * existing ones immediately (same "make it true right away, not as a side effect of a
 * scope" reasoning account deletion already uses), but never touches their posts,
 * profile, or content visibility. Fully and instantly reversible.
 */
final class SetUserActiveState
{
    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    public function __invoke(User $user, bool $active): void
    {
        if ($user->is_active === $active) {
            return;
        }

        $user->forceFill([
            'is_active' => $active,
            'visibility_state' => $active ? UserVisibilityState::Visible : UserVisibilityState::Hidden,
            'moderation_state' => $active ? UserModerationState::Clear : UserModerationState::Suspended,
            'moderated_at' => $active ? null : now(),
        ]);
        $user->save();

        if (! $active) {
            ($this->revokeSessions)($user, SessionEndReason::Revoked);
        }
    }
}
