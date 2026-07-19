<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

class BlockService
{
    public function __construct(private readonly FollowService $follows) {}

    /**
     * Idempotent — blocking an already-blocked user is a no-op. Severs any existing follow
     * edge in both directions, since a block that leaves the follow graph intact would be
     * trivially pointless (blocked user's posts would keep showing up in the blocker's feed).
     */
    public function block(User $blocker, User $target): void
    {
        if ($blocker->is($target)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot block yourself.',
            ]);
        }

        if (! $blocker->blockedUsers()->where('blocked_id', $target->id)->exists()) {
            $blocker->blockedUsers()->attach($target->id);
        }

        $this->follows->unfollow($blocker, $target);
        $this->follows->unfollow($target, $blocker);
    }

    /** Idempotent — unblocking a user you haven't blocked is a no-op. */
    public function unblock(User $blocker, User $target): void
    {
        $blocker->blockedUsers()->detach($target->id);
    }

    /** @return CursorPaginator<int, User&object{pivot: Pivot}> */
    public function blockedUsers(User $user, int $perPage = 20): CursorPaginator
    {
        return $user->blockedUsers()->cursorPaginate($perPage);
    }

    /** True if either user has blocked the other. */
    public function isBlockedEitherWay(User $a, User $b): bool
    {
        return $a->blockedUsers()->where('blocked_id', $b->id)->exists()
            || $a->blockedBy()->where('blocker_id', $b->id)->exists();
    }

    /**
     * Excludes rows authored by anyone blocked either-way by the viewer, keyed off the
     * given column (e.g. 'user_id' on posts). Shared primitive for any query that lists
     * content by author — feed, search, hashtag browse, explore all reuse this.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function excludeBlocked(Builder $query, User $viewer, string $userColumn): Builder
    {
        $blockedEitherWay = $viewer->blockedUsers()->pluck('users.id')
            ->merge($viewer->blockedBy()->pluck('users.id'));

        if ($blockedEitherWay->isEmpty()) {
            return $query;
        }

        return $query->whereNotIn($userColumn, $blockedEitherWay);
    }
}
