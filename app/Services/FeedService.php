<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;

class FeedService
{
    /**
     * Posts from followed users only, reverse-chronological — excludes the viewer's own posts.
     *
     * @return CursorPaginator<int, Post>
     */
    public function feedFor(User $user, int $perPage = 15): CursorPaginator
    {
        return Post::query()
            ->whereIn('user_id', $user->following()->pluck('users.id'))
            ->with(['user', 'media'])
            ->withCount(['likes', 'comments'])
            ->latest('id')
            ->cursorPaginate($perPage);
    }
}
