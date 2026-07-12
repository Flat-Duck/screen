<?php

namespace App\Console\Commands;

use App\Actions\Posts\PurgePost;
use App\Models\Post;
use Illuminate\Console\Command;

class PrunePendingDeletedPosts extends Command
{
    /** @var string */
    protected $signature = 'posts:prune-deleted';

    /** @var string */
    protected $description = 'Permanently deletes soft-deleted posts (and their media files) past the retention window.';

    public function handle(PurgePost $purgePost): int
    {
        $cutoff = now()->subDays((int) config('social.post_retention_days', 30));

        $pending = Post::onlyTrashed()->where('deleted_at', '<', $cutoff)->with('media')->get();

        foreach ($pending as $post) {
            $purgePost($post);
        }

        $this->info("Purged {$pending->count()} post(s) past the retention window.");

        return self::SUCCESS;
    }
}
