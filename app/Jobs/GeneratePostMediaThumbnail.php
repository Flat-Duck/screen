<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostMedia;
use App\Services\ImageProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Generates the feed-thumbnail for one PostMedia. Dispatched once per image (not once per
 * post) so a single bad image doesn't block the rest of a carousel, and workers can process
 * a multi-image post in parallel. No-ops cleanly if the media/post was deleted before the
 * job ran — nothing to do, nothing to report.
 */
class GeneratePostMediaThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $postMediaId) {}

    public function handle(ImageProcessingService $images): void
    {
        $media = PostMedia::find($this->postMediaId);

        if (! $media) {
            return;
        }

        $thumbnailPath = sprintf(
            '%s/%s-thumb.webp',
            dirname($media->original_path),
            pathinfo($media->original_path, PATHINFO_FILENAME),
        );

        $images->generateThumbnail($media->original_path, $thumbnailPath);

        $media->update([
            'thumbnail_path' => $thumbnailPath,
            'status' => PostMedia::STATUS_READY,
        ]);

        if ($media->post) {
            $this->syncPostStatus($media->post);
        }
    }

    /**
     * Marks the job's own PostMedia as failed once retries are exhausted, so it stops
     * blocking the parent post's status from ever resolving.
     */
    public function failed(Throwable $exception): void
    {
        $media = PostMedia::find($this->postMediaId);

        if (! $media) {
            return;
        }

        $media->update(['status' => PostMedia::STATUS_FAILED]);

        if ($media->post) {
            $this->syncPostStatus($media->post);
        }
    }

    /** Flips the parent Post's status once every sibling media has finished processing. */
    private function syncPostStatus(Post $post): void
    {
        $siblings = $post->media()->get();

        if ($siblings->contains(fn (PostMedia $media) => $media->status === PostMedia::STATUS_PENDING)) {
            return;
        }

        $post->update([
            'status' => $siblings->contains(fn (PostMedia $media) => $media->status === PostMedia::STATUS_FAILED)
                ? Post::STATUS_FAILED
                : Post::STATUS_READY,
        ]);
    }
}
