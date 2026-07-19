<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class FeedService
{
    public function __construct(
        private readonly BlockService $blocks,
        private readonly MuteService $mutes,
    ) {}

    /**
     * Posts from followed users only, reverse-chronological — excludes the viewer's own posts.
     *
     * Blocking already auto-unfollows both directions (BlockService::block()), so the block
     * exclusion here is defense-in-depth rather than the primary mechanism — it covers any
     * state that predates a block or reaches this query some other way. Muting, unlike
     * blocking, doesn't touch the follow graph at all, so the mute exclusion here *is* the
     * only mechanism — a muted author stays followed, just hidden from this feed.
     *
     * @return CursorPaginator<int, Post>
     */
    public function feedFor(User $user, int $perPage = 15): CursorPaginator
    {
        $query = Post::query()
            ->whereIn('user_id', $user->following()->pluck('users.id'))
            ->with(['user', 'media'])
            ->withCount(['likes', 'comments'])
            ->latest('id');

        $query = $this->blocks->excludeBlocked($query, $user, 'user_id');
        $query = $this->mutes->excludeMuted($query, $user, 'user_id');

        return $query->cursorPaginate($perPage);
    }

    /**
     * Splices a handful of top-scoring posts from accounts the viewer doesn't follow into
     * the given (already-fetched) page, at config('social.trending.discovery_positions').
     * Only ever call this for a fresh/first page load — mixing discovery posts into a later
     * cursor page would make the cursor's position meaningless, so callers gate this on
     * "no cursor param was given" (see FeedController::index).
     *
     * @param  CursorPaginator<int, Post>  $posts
     */
    public function injectDiscovery(CursorPaginator $posts, User $user): void
    {
        $positions = config('social.trending.discovery_positions', [3, 8]);

        $discovery = $this->discoveryCandidates($user, count($positions))->values();

        if ($discovery->isEmpty()) {
            return;
        }

        $items = $posts->getCollection()->all();

        foreach ($positions as $i => $position) {
            if (! isset($discovery[$i])) {
                break;
            }

            array_splice($items, min($position, count($items)), 0, [$discovery[$i]]);
        }

        $posts->setCollection(collect($items));
    }

    /**
     * Top-scoring recent posts (per `posts:refresh-trending`) from accounts the viewer
     * doesn't already follow and isn't themselves — the "out-of-network" half of the feed.
     * Discovery is a nice-to-have, never allowed to break the core chronological feed: an
     * empty/unreachable Redis (fresh install, job hasn't run yet, Redis down) just yields no
     * discovery posts rather than an error.
     *
     * @return Collection<int, Post>
     */
    public function discoveryCandidates(User $user, int $limit): Collection
    {
        try {
            // Pull a wider pool than $limit so there's enough left after filtering out
            // already-followed authors and the viewer's own posts.
            $ids = Redis::zrevrange(config('social.trending.redis_key', 'trending:posts'), 0, 49);
        } catch (Throwable $e) {
            report($e);

            return collect();
        }

        if (empty($ids)) {
            return collect();
        }

        $query = Post::query()
            ->whereIn('id', $ids)
            ->where('user_id', '!=', $user->id)
            ->whereNotIn('user_id', $user->following()->pluck('users.id'))
            ->with(['user', 'media'])
            ->withCount(['likes', 'comments']);

        $posts = $this->blocks->excludeBlocked($query, $user, 'user_id')->get();

        // Redis returns ids in rank order (highest score first); preserve that ordering
        // since the DB query above doesn't.
        $rank = array_flip($ids);

        return $posts
            ->sortBy(fn (Post $post): int => $rank[(string) $post->id] ?? PHP_INT_MAX)
            ->take($limit)
            ->values();
    }
}
