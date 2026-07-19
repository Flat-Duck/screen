<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentLikedNotification;
use App\Notifications\PostLikedNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * `likes` is polymorphic (likeable_type/likeable_id) so posts and comments share one
 * table/service instead of a parallel comment_likes table — mirrors how `reports` already
 * spans post/comment/user via reportable_type/reportable_id.
 */
class LikeService
{
    public function __construct(private readonly MuteService $mutes) {}

    /** Idempotent — backed by the (likeable_type, likeable_id, user_id) unique constraint as a race-condition backstop. */
    public function like(User $user, Post|Comment $likeable): void
    {
        $like = Like::query()->firstOrCreate([
            'likeable_type' => $likeable::class,
            'likeable_id' => $likeable->id,
            'user_id' => $user->id,
        ]);

        if (! $like->wasRecentlyCreated) {
            return;
        }

        $owner = $likeable->user;

        if ($user->isNot($owner) && $this->mutes->shouldNotify($owner, $user)) {
            $owner->notify($this->notificationFor($likeable, $user));
        }
    }

    /** Idempotent — unliking something you haven't liked is a no-op. */
    public function unlike(User $user, Post|Comment $likeable): void
    {
        Like::query()
            ->where('likeable_type', $likeable::class)
            ->where('likeable_id', $likeable->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Sets `is_liked` on each post for the given viewer in a single query — avoids an
     * N+1 when annotating a feed/list before resourcing.
     *
     * @param  Collection<int, Post>  $posts
     */
    public function annotateIsLiked(Collection $posts, User $viewer): void
    {
        $this->annotateLikeableCollection($posts, $viewer, Post::class);
    }

    /**
     * Same as annotateIsLiked() but for a collection of comments.
     *
     * @param  Collection<int, Comment>  $comments
     */
    public function annotateCommentsAreLiked(Collection $comments, User $viewer): void
    {
        $this->annotateLikeableCollection($comments, $viewer, Comment::class);
    }

    /**
     * @template TItem of Post|Comment
     *
     * @param  Collection<int, TItem>  $items
     * @param  class-string<TItem>  $class
     */
    private function annotateLikeableCollection(Collection $items, User $viewer, string $class): void
    {
        $likedIds = Like::query()
            ->where('user_id', $viewer->id)
            ->where('likeable_type', $class)
            ->whereIn('likeable_id', $items->pluck('id'))
            ->pluck('likeable_id')
            ->all();

        $items->each(function (Post|Comment $item) use ($likedIds): void {
            $item->is_liked = in_array($item->id, $likedIds, true);
        });
    }

    private function notificationFor(Post|Comment $likeable, User $liker): Notification
    {
        return $likeable instanceof Post
            ? new PostLikedNotification($likeable, $liker)
            : new CommentLikedNotification($likeable, $liker);
    }
}
