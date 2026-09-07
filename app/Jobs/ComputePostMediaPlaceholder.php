<?php

namespace App\Jobs;

use App\Models\PostMedia;
use App\Services\ImageProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Backfills the ThumbHash placeholder for media that predates the column.
 *
 * New media gets its hash for free inside {@see GeneratePostMediaThumbnail}, which already has the
 * image decoded; this exists only so the rows created before that did are not stuck without one.
 *
 * No status column and no `failed()` hook, unlike its sibling jobs: a missing placeholder degrades
 * to exactly the behaviour the app had before it existed — a plain box until the image lands — so
 * a failure here is not worth marking the media row over.
 */
class ComputePostMediaPlaceholder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $postMediaId)
    {
        $this->timeout = (int) config('social.media_job_timeout_seconds', 60);
        $this->onQueue('media');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(config('social.media_job_backoff_seconds', [30, 120, 300]));
    }

    public function handle(ImageProcessingService $images): void
    {
        $media = PostMedia::find($this->postMediaId);

        // Idempotent: re-running over a batch that partly succeeded costs nothing.
        if (! $media || $media->thumbhash !== null) {
            return;
        }

        // The thumbnail where there is one — same resulting hash, a fraction of the bytes.
        $source = $media->thumbnail_path ?: $media->original_path;

        if ($source === '') {
            return;
        }

        $media->update([
            'thumbhash' => $images->placeholderFrom($source, $media->source_disk),
        ]);
    }
}
