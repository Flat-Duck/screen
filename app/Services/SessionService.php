<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class SessionService
{
    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function listFor(User $user): Collection
    {
        return $user->tokens()->latest('last_used_at')->get();
    }

    /**
     * Scoped to the user's own tokens — never a bare PersonalAccessToken::find(), so one
     * user can't revoke another's session by guessing an ID. Idempotent: revoking a
     * token that doesn't exist (or isn't yours) is a silent no-op, matching this API's
     * existing unlike/unfollow convention.
     */
    public function revoke(User $user, int $tokenId): void
    {
        $user->tokens()->where('id', $tokenId)->delete();
    }

    /** Leaves the token used for this very request untouched. */
    public function revokeOthers(User $user, int $currentTokenId): void
    {
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();
    }
}
