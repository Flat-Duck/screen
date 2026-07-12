<?php

namespace App\Console\Commands;

use App\Actions\Posts\PruneExpiredPosts;
use Illuminate\Console\Command;

class PrunePendingDeletedPosts extends Command
{
    /** @var string */
    protected $signature = 'posts:prune-deleted';

    /** @var string */
    protected $description = 'Permanently deletes soft-deleted posts (and their media files) past the retention window.';

    public function handle(PruneExpiredPosts $prune): int
    {
        $cutoff = now()->subDays((int) config('social.post_retention_days', 30));

        $summary = $prune($cutoff);
        $this->info("Post purge complete: {$summary->purged} purged, {$summary->busy} busy, {$summary->failed} failed.");

        return $summary->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
