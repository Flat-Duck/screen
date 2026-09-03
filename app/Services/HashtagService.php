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
            ->withCount(['likes', 'comments', 'reposts'])
            ->latest('id');

        return $this->blocks->excludeBlocked($query, $viewer, 'user_id')->cursorPaginate($perPage);
    }

    /**
     * Global, not per-viewer — same "public accounts, not the full per-viewer follow graph"
     * baseline `FeedService::explore()` already uses for its own non-personalized candidate
     * set, rather than `Post::visibleTo()`'s full private-account/following check (that would
     * make "trending" different for every viewer, and be far more expensive to rank by).
     * Ranked by activity within the last `$withinDays`, but `posts_count` on the returned
     * models is each hashtag's real all-time total (`withCount('posts')`, same as
     * followedHashtagsFor()) — the window only decides *which* tags rank, not the number shown.
     *
     * @return Collection<int, Hashtag>
     */
    public function trending(int $limit = 10, int $withinDays = 7): Collection
    {
        $eligiblePostIds = Post::query()
            ->fromPubliclyVisibleAuthors()
            ->whereNull('archived_at')
            ->select('id');

        $rankedIds = DB::table('hashtag_post')
            ->whereIn('post_id', $eligiblePostIds)
            ->where('created_at', '>=', now()->subDays($withinDays))
            ->select('hashtag_id', DB::raw('count(*) as recent_count'))
            ->groupBy('hashtag_id')
            ->orderByDesc('recent_count')
            ->limit($limit)
            ->pluck('recent_count', 'hashtag_id');

        if ($rankedIds->isEmpty()) {
            return collect();
        }

        return Hashtag::query()
            ->whereIn('id', $rankedIds->keys())
            ->withCount('posts')
            ->get()
            ->sortByDesc(fn (Hashtag $hashtag): int => $rankedIds[$hashtag->id])
            ->values();
    }

    /**
     * Sets `is_followed` on each hashtag for the given viewer in a single query — same
     * pattern as LikeService::annotateLikes.
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
