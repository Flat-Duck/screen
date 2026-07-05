<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

class FollowService
{
    /** Idempotent — following an already-followed user is a no-op. */
    public function follow(User $follower, User $target): void
    {
        if ($follower->is($target)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot follow yourself.',
            ]);
        }

        if (! $follower->following()->where('followee_id', $target->id)->exists()) {
            $follower->following()->attach($target->id);
        }
    }

    /** Idempotent — unfollowing a user you don't follow is a no-op. */
    public function unfollow(User $follower, User $target): void
    {
        $follower->following()->detach($target->id);
    }

    /** @return CursorPaginator<int, User&object{pivot: Pivot}> */
    public function followers(User $user, int $perPage = 20): CursorPaginator
    {
        return $user->followers()->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, User&object{pivot: Pivot}> */
    public function following(User $user, int $perPage = 20): CursorPaginator
    {
        return $user->following()->cursorPaginate($perPage);
    }
}
