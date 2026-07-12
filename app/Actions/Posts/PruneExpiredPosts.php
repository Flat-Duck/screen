<?php

namespace App\Actions\Posts;

use App\Data\Maintenance\PruneSummary;
use App\Enums\PostPurgeOutcome;
use App\Models\Post;
use DateTimeInterface;
use Throwable;

final class PruneExpiredPosts
{
    public function __construct(private readonly PurgePost $purgePost) {}

    public function __invoke(DateTimeInterface $cutoff): PruneSummary
    {
        $purged = $busy = $alreadyGone = $failed = 0;

        foreach (Post::onlyTrashed()->where('deleted_at', '<', $cutoff)->select('id')->lazyById(100) as $post) {
            try {
                $outcome = ($this->purgePost)($post->id);
                $purged += $outcome === PostPurgeOutcome::Purged ? 1 : 0;
                $busy += $outcome === PostPurgeOutcome::Busy ? 1 : 0;
                $alreadyGone += $outcome === PostPurgeOutcome::AlreadyGone ? 1 : 0;
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        return new PruneSummary($purged, $busy, $alreadyGone, $failed);
    }
}
