<?php

namespace App\Services;

use App\Models\Post;
use App\Models\SavedPost;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

class SavedPostService
{
    /** Idempotent — saving an already-saved post is a no-op. */
    public function save(User $user, Post $post): void
    {
        SavedPost::query()->firstOrCreate([
            'user_id' => $user->id,
            'post_id' => $post->id,
        ]);
    }

    /** Idempotent — unsaving a post you haven't saved is a no-op. */
    public function unsave(User $user, Post $post): void
    {
        SavedPost::query()->where('user_id', $user->id)->where('post_id', $post->id)->delete();
    }

    /** Single-post check for a detail view — see annotateIsSaved() for annotating a list. */
    public function isSaved(User $user, Post $post): bool
    {
        return SavedPost::query()->where('user_id', $user->id)->where('post_id', $post->id)->exists();
    }

    /**
     * Always "my" saved posts — there's no {user} route param, same as GET /settings —
     * saved posts are private, never another user's to view. Ordered by post creation
     * (not save time) — a `whereIn` subquery, not a join, so the cursor keeps ordering by
     * a plain `posts` column rather than one from a joined table.
     *
     * @return CursorPaginator<int, Post>
     */
    public function savedPostsFor(User $user, int $perPage = 15): CursorPaginator
    {
        return Post::query()
            ->visibleTo($user)
            ->whereIn('id', SavedPost::query()->where('user_id', $user->id)->select('post_id'))
            ->with(['user', 'media'])
            ->withCount(['likes', 'comments'])
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Sets `is_saved` on each post for the given viewer in a single query — avoids an
     * N+1 when annotating a feed/list before resourcing, same pattern as
     * LikeService::annotateIsLiked.
     *
     * @param  Collection<int, Post>  $posts
     */
    public function annotateIsSaved(Collection $posts, User $viewer): void
    {
        $savedPostIds = SavedPost::query()
            ->where('user_id', $viewer->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->pluck('post_id')
            ->all();

        $posts->each(function (Post $post) use ($savedPostIds): void {
            $post->is_saved = in_array($post->id, $savedPostIds, true);
        });
    }
}
