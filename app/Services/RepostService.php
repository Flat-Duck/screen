<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Repost;
use App\Models\User;
use App\Notifications\PostRepostedNotification;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;

/**
 * v1 is profile-only — a repost surfaces on the reposting user's own profile
 * (GET /users/{id}/reposts) but is never blended into followers' home feeds. Merging two
 * differently-shaped sources into one cursor-paginated feed is real cursor-correctness risk,
 * explicitly deferred to a v2 that can be scoped and tested on its own.
 */
class RepostService
{
    public function __construct(private readonly MuteService $mutes) {}

    /** Idempotent — reposting an already-reposted post is a no-op (doesn't update the comment). */
    public function repost(User $user, Post $post, ?string $comment = null): void
    {
        if ($user->is($post->user)) {
            throw ValidationException::withMessages([
                'post' => 'You cannot repost your own post.',
            ]);
        }

        $repost = Repost::query()->firstOrCreate(
            ['user_id' => $user->id, 'post_id' => $post->id],
            ['comment' => $comment],
        );

        if ($repost->wasRecentlyCreated && $this->mutes->shouldNotify($post->user, $user)) {
            $post->user->notify(new PostRepostedNotification($post, $user, $comment));
        }
    }

    /** Idempotent — un-reposting a post you haven't reposted is a no-op. */
    public function unrepost(User $user, Post $post): void
    {
        Repost::query()->where('user_id', $user->id)->where('post_id', $post->id)->delete();
    }

    /** @return CursorPaginator<int, Repost> */
    public function repostsFor(User $user, int $perPage = 15): CursorPaginator
    {
        return Repost::query()
            ->where('user_id', $user->id)
            ->with(['post' => function ($query): void {
                $query->with(['user', 'media'])->withCount(['likes', 'comments']);
            }])
            ->latest('id')
            ->cursorPaginate($perPage);
    }
}
