<?php

namespace App\Services;

use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Following a hashtag is a bookmark list only, v1 — deliberately does not notify on every
 * new post under a followed tag (unbounded volume for popular tags) and does not inject
 * followed-tag posts into the main feed (a clearly-scoped v2 enhancement).
 *
 * Paginated queries go through plain `Hashtag::query()`/`Post::query()` rather than the
 * `BelongsToMany` relations directly — same reasoning as SavedPostService: keeps the
 * concrete Eloquent builder type (and therefore excludeBlocked()/cursor pagination) simple,
 * rather than a relation's pivot-carrying generic type.
 */
class HashtagService
{
    public function __construct(private readonly BlockService $blocks) {}

    /** Idempotent — following an already-followed hashtag is a no-op. */
    public function follow(User $user, Hashtag $hashtag): void
    {
        if (! $this->isFollowing($user, $hashtag)) {
            $user->followedHashtags()->attach($hashtag->id);
        }
    }

    /** Idempotent — unfollowing a hashtag you don't follow is a no-op. */
    public function unfollow(User $user, Hashtag $hashtag): void
    {
        $user->followedHashtags()->detach($hashtag->id);
    }

    public function isFollowing(User $user, Hashtag $hashtag): bool
    {
        return $user->followedHashtags()->where('hashtags.id', $hashtag->id)->exists();
    }

    /** @return CursorPaginator<int, Hashtag> */
    public function followedHashtagsFor(User $user, int $perPage = 20): CursorPaginator
    {
        return Hashtag::query()
            ->whereIn('id', DB::table('hashtag_user')->where('user_id', $user->id)->select('hashtag_id'))
            ->withCount('posts')
            ->orderBy('name')
            ->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, Post> */
    public function postsFor(Hashtag $hashtag, User $viewer, int $perPage = 15): CursorPaginator
    {
        $query = Post::query()
            ->visibleTo($viewer)
            ->whereIn('id', DB::table('hashtag_post')->where('hashtag_id', $hashtag->id)->select('post_id'))
            ->with(['user', 'media', 'category'])
            ->withCount(['likes', 'comments'])
            ->latest('id');

        return $this->blocks->excludeBlocked($query, $viewer, 'user_id')->cursorPaginate($perPage);
    }

    /**
     * Sets `is_followed` on each hashtag for the given viewer in a single query — same
     * pattern as LikeService::annotateIsLiked.
     *
     * @param  Collection<int, Hashtag>  $hashtags
     */
    public function annotateIsFollowed(Collection $hashtags, User $viewer): void
    {
        $followedIds = $viewer->followedHashtags()
            ->whereIn('hashtags.id', $hashtags->pluck('id'))
            ->pluck('hashtags.id')
            ->all();

        $hashtags->each(function (Hashtag $hashtag) use ($followedIds): void {
            $hashtag->is_followed = in_array($hashtag->id, $followedIds, true);
        });
    }
}
