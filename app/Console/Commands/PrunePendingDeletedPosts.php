<?php

namespace App\Console\Commands;

use App\Actions\Posts\PurgePost;
use App\Enums\PostPurgeOutcome;
use App\Models\Post;
use Illuminate\Console\Command;
use Throwable;

class PrunePendingDeletedPosts extends Command
{
    /** @var string */
    protected $signature = 'posts:prune-deleted';

    /** @var string */
    protected $description = 'Permanently deletes soft-deleted posts (and their media files) past the retention window.';

    public function handle(PurgePost $purgePost): int
    {
        $cutoff = now()->subDays((int) config('social.post_retention_days', 30));

        $purged = 0;
        $busy = 0;
        $failed = 0;

        foreach (Post::onlyTrashed()->where('deleted_at', '<', $cutoff)->select('id')->lazyById(100) as $post) {
            try {
                $outcome = $purgePost($post->id);
                $purged += $outcome === PostPurgeOutcome::Purged ? 1 : 0;
                $busy += $outcome === PostPurgeOutcome::Busy ? 1 : 0;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Failed to purge post #{$post->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Post purge complete: {$purged} purged, {$busy} busy, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
