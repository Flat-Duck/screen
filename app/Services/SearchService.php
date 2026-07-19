<?php

namespace App\Services;

use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;

/**
 * Plain `LIKE`-based matching, no dedicated search engine — driver-agnostic (prod runs
 * Postgres, the test suite runs sqlite in-memory, so a Postgres-only tsvector/GIN approach
 * would break test portability). Ordered by a stable column rather than a computed
 * relevance score, to keep cursor pagination well-defined; smarter ranking (e.g. prefix
 * matches ahead of substring matches) is an explicit v2 follow-up, not attempted here.
 */
class SearchService
{
    public function __construct(private readonly BlockService $blocks) {}

    /** @return CursorPaginator<int, User> */
    public function users(string $query, User $viewer, int $perPage = 20): CursorPaginator
    {
        $searchQuery = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $viewer->id)
            ->where(function ($q) use ($query): void {
                $q->where('username', 'like', "%{$query}%")
                    ->orWhere('name', 'like', "%{$query}%");
            })
            ->orderBy('username');

        return $this->blocks->excludeBlocked($searchQuery, $viewer, 'id')->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, Post> */
    public function posts(string $query, User $viewer, int $perPage = 20): CursorPaginator
    {
        $searchQuery = Post::query()
            ->where('caption', 'like', "%{$query}%")
            ->with(['user', 'media'])
            ->withCount(['likes', 'comments'])
            ->latest('id');

        return $this->blocks->excludeBlocked($searchQuery, $viewer, 'user_id')->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, Hashtag> */
    public function hashtags(string $query, int $perPage = 20): CursorPaginator
    {
        return Hashtag::query()
            ->where('name', 'like', '%'.Hashtag::normalize($query).'%')
            ->withCount('posts')
            ->orderBy('name')
            ->cursorPaginate($perPage);
    }
}
