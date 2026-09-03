<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Notifications\NewFollowerNotification;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FollowService
{
    public function __construct(private readonly MuteService $mutes) {}

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

            if ($this->mutes->shouldNotify($target, $follower)) {
                $target->notify(new NewFollowerNotification($follower));
            }
        }
    }

    /** Idempotent — unfollowing a user you don't follow is a no-op. */
    public function unfollow(User $follower, User $target): void
    {
        $follower->following()->detach($target->id);
    }

    /**
     * Sets `is_following` on each post's author for the given viewer in one query — the same
     * bulk-annotate shape as `LikeService::annotateLikes`, and for the same reason: without it,
     * rendering a follow button per row is an N+1.
     *
     * Needed because `UserSummaryResource` (post authors, search results, notification actors) has
     * no follow state of its own, so every client had to assume "not following" and the Following
     * feed showed a Follow button on people you already follow.
     *
     * A post authored by the viewer is left `null`, not `false` — "am I following myself" is not a
     * meaningful question, and the clients hide the button entirely in that case.
     *
     * @param  Collection<int, Post>  $posts
     */
    public function annotatePostAuthorsAreFollowed(Collection $posts, User $viewer): void
    {
        $authors = $posts->map(fn (Post $post): ?User => $post->relationLoaded('user') ? $post->user : null)
            ->filter()
            ->unique('id');

        if ($authors->isEmpty()) {
            return;
        }

        $followedIds = $viewer->following()
            ->whereIn('users.id', $authors->pluck('id'))
            ->pluck('users.id')
            ->all();

        $authors->each(function (User $author) use ($followedIds, $viewer): void {
            $author->is_following = $author->id === $viewer->id
                ? null
                : in_array($author->id, $followedIds, true);
        });
    }

    /** @return CursorPaginator<int, User&object{pivot: Pivot}> */
    public function followers(User $user, int $perPage = 20): CursorPaginator
    {
        return $user->followers()->publiclyVisible()->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, User&object{pivot: Pivot}> */
    public function following(User $user, int $perPage = 20): CursorPaginator
    {
        return $user->following()->publiclyVisible()->cursorPaginate($perPage);
    }
}
