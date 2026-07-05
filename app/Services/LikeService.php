<?php

namespace App\Services;

use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

class LikeService
{
    /** Idempotent — backed by the (post_id, user_id) unique constraint as a race-condition backstop. */
    public function like(User $user, Post $post): void
    {
        Like::query()->firstOrCreate([
            'post_id' => $post->id,
            'user_id' => $user->id,
        ]);
    }

    /** Idempotent — unliking a post you haven't liked is a no-op. */
    public function unlike(User $user, Post $post): void
    {
        Like::query()->where('post_id', $post->id)->where('user_id', $user->id)->delete();
    }

    /**
     * Sets `is_liked` on each post for the given viewer in a single query — avoids an
     * N+1 when annotating a feed/list before resourcing.
     *
     * @param  Collection<int, Post>  $posts
     */
    public function annotateIsLiked(Collection $posts, User $viewer): void
    {
        $likedPostIds = Like::query()
            ->where('user_id', $viewer->id)
            ->whereIn('post_id', $posts->pluck('id'))
            ->pluck('post_id')
            ->all();

        $posts->each(function (Post $post) use ($likedPostIds): void {
            $post->is_liked = in_array($post->id, $likedPostIds, true);
        });
    }
}
