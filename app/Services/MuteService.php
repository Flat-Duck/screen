<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

/**
 * Muting is one-directional and purely viewer-side: unlike Block, it never restricts what
 * the muted user can do or see — it only filters the muter's own feed and notifications.
 */
class MuteService
{
    /** Idempotent — muting an already-muted user is a no-op. */
    public function mute(User $muter, User $target): void
    {
        if ($muter->is($target)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot mute yourself.',
            ]);
        }

        if (! $this->isMuted($muter, $target)) {
            $muter->mutedUsers()->attach($target->id);
        }
    }

    /** Idempotent — unmuting a user you haven't muted is a no-op. */
    public function unmute(User $muter, User $target): void
    {
        $muter->mutedUsers()->detach($target->id);
    }

    /** @return CursorPaginator<int, User&object{pivot: Pivot}> */
    public function mutedUsers(User $user, int $perPage = 20): CursorPaginator
    {
        return $user->mutedUsers()->cursorPaginate($perPage);
    }

    public function isMuted(User $muter, User $target): bool
    {
        return $muter->mutedUsers()->where('muted_id', $target->id)->exists();
    }

    /**
     * Whether $actor's action toward $recipient should trigger a notification — suppressed
     * if $recipient has muted $actor. Consumed by FollowService/LikeService/CommentService
     * before calling ->notify(). Doesn't separately re-check blocking: every call site that
     * can reach this already rejects blocked-either-way pairs earlier, at the controller
     * layer (see BlockService::isBlockedEitherWay usage in FollowController/LikeController/
     * CommentController), so a blocked pair never gets far enough to ask this question.
     */
    public function shouldNotify(User $recipient, User $actor): bool
    {
        return ! $this->isMuted($recipient, $actor);
    }

    /**
     * Excludes rows authored by anyone the viewer has muted, keyed off the given column
     * (e.g. 'user_id' on posts). Unlike BlockService::excludeBlocked, this is one-directional
     * — being muted doesn't hide the muter's own content from the muted user.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function excludeMuted(Builder $query, User $viewer, string $userColumn): Builder
    {
        $muted = $viewer->mutedUsers()->pluck('users.id');

        if ($muted->isEmpty()) {
            return $query;
        }

        return $query->whereNotIn($userColumn, $muted);
    }
}
