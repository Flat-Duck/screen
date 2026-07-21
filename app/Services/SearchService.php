<?php

namespace App\Services;

use App\Enums\UserModerationState;
use App\Enums\UserVisibilityState;
use App\Models\Hashtag;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scout database search: PostgreSQL full-text relevance for screenshot posts and prefix
 * matching for usernames/hashtags. Tests use Scout's collection engine so authorization
 * and API behavior remain SQLite-testable; PostgreSQL integration tests own database-specific
 * relevance/index behavior.
 */
class SearchService
{
    /** @return LengthAwarePaginator<int, User> */
    public function users(string $query, User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $blockedIds = $viewer->blockedUsers()->pluck('users.id')
            ->merge($viewer->blockedBy()->pluck('users.id'))
            ->all();

        return User::search($query, function (Builder $searchQuery) use ($viewer, $blockedIds): void {
            $searchQuery->where('is_active', true)
                ->where('visibility_state', UserVisibilityState::Visible->value)
                ->where('moderation_state', UserModerationState::Clear->value)
                ->whereKeyNot($viewer->id)
                ->when($blockedIds !== [], fn (Builder $query) => $query->whereNotIn('id', $blockedIds));
        })->paginate($perPage);
    }

    /** @return LengthAwarePaginator<int, Post> */
    public function posts(string $query, User $viewer, int $perPage = 20): LengthAwarePaginator
    {
        $blockedIds = $viewer->blockedUsers()->pluck('users.id')
            ->merge($viewer->blockedBy()->pluck('users.id'))
            ->all();

        return Post::search($query, function (Builder $searchQuery) use ($blockedIds, $viewer): void {
            $visibleAuthorIds = User::query()->publiclyVisible()
                ->where(fn (Builder $users) => $users
                    ->where('account_visibility', 'public')
                    ->orWhere('id', $viewer->id)
                    ->orWhereIn('id', $viewer->following()->select('users.id')))
                ->select('id');

            $searchQuery->whereIn('user_id', $visibleAuthorIds)
                ->when($blockedIds !== [], fn (Builder $query) => $query->whereNotIn('user_id', $blockedIds))
                ->with(['user', 'media', 'category'])
                ->withCount(['likes', 'comments']);
        })->paginate($perPage);
    }

    /** @return LengthAwarePaginator<int, Hashtag> */
    public function hashtags(string $query, int $perPage = 20): LengthAwarePaginator
    {
        return Hashtag::search(Hashtag::normalize($query), function (Builder $searchQuery): void {
            $searchQuery->withCount('posts');
        })->paginate($perPage);
    }
}
