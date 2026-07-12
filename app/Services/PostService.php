<?php

namespace App\Services;

use App\Actions\Posts\PurgePost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PostService
{
    /**
     * Soft-deletes the post only — files stay on disk so a soft-deleted post remains
     * recoverable/inspectable until {@see PurgePost} runs it past
     * the retention window.
     */
    public function deletePost(Post $post): void
    {
        $post->delete();
    }

    /** @return CursorPaginator<int, Post> */
    public function postsForUser(User $user, int $perPage = 12): CursorPaginator
    {
        return $user->posts()
            ->with('media')
            ->withCount(['likes', 'comments'])
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    /**
     * The user's most-used hashtags across their own posts, most-used first — powers a
     * profile screen's "top tags" chips. A raw query builder aggregate rather than going
     * through Eloquent's `Post`/`Hashtag` models, since there's no model hydration
     * needed here — just counts. Soft-deleted posts don't count (the join bypasses
     * Post's model-level soft-delete scope, so it's filtered explicitly).
     *
     * @return Collection<int, array{name: string, posts_count: int}>
     */
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
