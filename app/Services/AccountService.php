<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * Soft-deletes the account and everything that needs to disappear from other
     * users' view immediately (not just at final purge, which is `users:prune-deleted`'s
     * job — see AccountService's caller and config('social.account_retention_days')).
     *
     * Posts are bulk soft-deleted rather than left alone: `Post` already carries its own
     * `SoftDeletes` scope, so soft-deleting them here is what makes them vanish from
     * feeds/profiles/discovery immediately, for free, via the same scope every other
     * soft-deleted post already relies on — no extra "is the author still around"
     * filtering needed anywhere else. Likes/comments the account made on *other* users'
     * posts are deliberately left alone (matches how a deleted post's own likes/comments
     * are handled — cascade cleanup happens at purge time, not immediately).
     *
     * Tokens are revoked outright rather than left to the soft-delete scope: a
     * soft-deleted user's tokens would fail to resolve via Sanctum's morph lookup anyway
     * (User::query() excludes trashed rows), but revoking now is more honest — it makes
     * "logged out everywhere" true immediately rather than as a side effect of a scope.
     */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->posts()->delete();
            $user->tokens()->delete();
            $user->delete();
        });
    }
}
