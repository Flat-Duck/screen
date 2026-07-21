<?php

namespace App\Actions\Posts;

use App\Contracts\MediaFileStore;
use App\Enums\PostPurgeOutcome;
use App\Enums\PostPurgeStatus;
use App\Models\Post;
use App\Models\Scopes\NotArchivedScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Permanently removes a (soft-deleted) post: deletes each media's files from disk, then
 * force-deletes the DB rows. The FK's cascade delete stays only as a referential-integrity
 * backstop, since a raw cascade wouldn't fire Eloquent events and would leak orphaned files.
 *
 * Only called by the scheduled prune commands — never directly from a controller.
 */
class PurgePost
{
    public function __construct(private readonly MediaFileStore $files) {}

    public function __invoke(int $postId): PostPurgeOutcome
    {
        $lock = Cache::lock("post-purge:{$postId}", 300);

        if (! $lock->get()) {
            return PostPurgeOutcome::Busy;
        }

        try {
            $post = Post::withoutGlobalScope(NotArchivedScope::class)->onlyTrashed()->with('media')->find($postId);

            if (! $post) {
                return PostPurgeOutcome::AlreadyGone;
            }

            $post->forceFill([
                'purge_status' => PostPurgeStatus::Purging,
                'purge_attempted_at' => now(),
                'purge_error' => null,
            ])->save();

            try {
                $paths = $post->media->flatMap(
                    static fn ($media): array => array_values(array_filter([$media->original_path, $media->thumbnail_path]))
                )->values()->all();

                $this->files->deletePaths(array_values($paths));
                $post->forceDelete();
            } catch (Throwable $exception) {
                $this->recordFailure($post, $exception);
                report($exception);

                throw $exception;
            }

            return PostPurgeOutcome::Purged;
        } finally {
            $lock->release();
        }
    }

    private function recordFailure(Post $post, Throwable $exception): void
    {
        if (! $post->exists) {
            return;
        }

        try {
            $post->forceFill([
                'purge_status' => PostPurgeStatus::Failed,
                'purge_attempted_at' => now(),
                'purge_error' => Str::limit($exception::class.': '.$exception->getMessage(), 2000, ''),
            ])->save();
        } catch (Throwable $stateException) {
            report($stateException);
        }
    }
}
