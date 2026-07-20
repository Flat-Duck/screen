<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PostQueryService
{
    /** @return CursorPaginator<int, Post> */
    public function postsForUser(User $user, User $viewer, int $perPage = 12): CursorPaginator
    {
        return Post::query()
            ->visibleTo($viewer)
            ->where('user_id', $user->id)
            ->with('media')
            ->withCount(['likes', 'comments'])
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    /** @return Collection<int, array{name: string, posts_count: int}> */
    public function topHashtagsFor(User $user, int $limit = 5): Collection
    {
        return DB::table('hashtags')
            ->join('hashtag_post', 'hashtag_post.hashtag_id', '=', 'hashtags.id')
            ->join('posts', 'posts.id', '=', 'hashtag_post.post_id')
            ->where('posts.user_id', $user->id)
            ->whereNull('posts.deleted_at')
            ->groupBy('hashtags.id', 'hashtags.name')
            ->selectRaw('hashtags.name as name, COUNT(*) as posts_count')
            ->orderByDesc('posts_count')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'posts_count' => (int) $row->posts_count]);
    }
}
