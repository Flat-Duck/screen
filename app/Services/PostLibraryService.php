<?php

namespace App\Services;

use App\Actions\Posts\PurgePost;
use App\Enums\PostPurgeOutcome;
use App\Models\Post;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PostLibraryService
{
    public function __construct(private readonly PurgePost $purgePost) {}

    public function archive(User $user, int $postId): void
    {
        $post = Post::withoutGlobalScope(NotArchivedScope::class)->whereKey($postId)->where('user_id', $user->id)->firstOrFail();
        if ($post->archived_at !== null) {
            return;
        }
        $post->archived_at = now();
        $post->save();
        $post->unsearchable();
    }

    public function unarchive(User $user, int $postId): void
    {
        $post = Post::withoutGlobalScope(NotArchivedScope::class)->whereKey($postId)
            ->where('user_id', $user->id)->firstOrFail();
        if ($post->archived_at === null) {
            return;
        }
        $post->archived_at = null;
        $post->save();
        if ($post->shouldBeSearchable()) {
            $post->searchable();
        }
    }

    /** @return CursorPaginator<int, Post> */
    public function archived(User $user, int $perPage = 15): CursorPaginator
    {
        return Post::withoutGlobalScope(NotArchivedScope::class)->where('user_id', $user->id)
            ->whereNotNull('archived_at')->with(['user', 'media', 'category'])->withCount(['likes', 'comments'])
            ->latest('archived_at')->latest('id')->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, Post> */
    public function recentlyDeleted(User $user, int $perPage = 15): CursorPaginator
    {
        return Post::withoutGlobalScope(NotArchivedScope::class)->onlyTrashed()->where('user_id', $user->id)
            ->whereNull('account_deleted_at')->where('deleted_at', '>=', now()->subDays((int) config('social.post_retention_days', 30)))
            ->with(['user', 'media', 'category'])->withCount(['likes', 'comments'])
            ->latest('deleted_at')->latest('id')->cursorPaginate($perPage);
    }

    public function restore(User $user, int $postId): Post
    {
        $post = $this->ownedTrashed($user, $postId);
        abort_if($post->deleted_at->lt(now()->subDays((int) config('social.post_retention_days', 30))), 410, 'The post retention window has expired.');
        if ($post->purge_status !== null) {
            throw new ConflictHttpException('This post has entered permanent cleanup and cannot be restored.');
        }
        $post->forceFill(['archived_at' => null, 'account_deleted_at' => null])->restore();
        if ($post->shouldBeSearchable()) {
            $post->searchable();
        }

        return $post;
    }

    public function permanentlyDelete(User $user, int $postId): void
    {
        $post = $this->ownedTrashed($user, $postId);
        $outcome = ($this->purgePost)($post->id);
        if ($outcome === PostPurgeOutcome::Busy) {
            throw new ConflictHttpException('Permanent deletion is already in progress.');
        }
        abort_if($outcome === PostPurgeOutcome::AlreadyGone, 404);
    }

    private function ownedTrashed(User $user, int $postId): Post
    {
        return Post::withoutGlobalScope(NotArchivedScope::class)->onlyTrashed()->whereKey($postId)
            ->where('user_id', $user->id)->firstOrFail();
    }
}
