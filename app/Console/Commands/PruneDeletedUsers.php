<?php

namespace App\Console\Commands;

use App\Actions\Posts\PurgePost;
use App\Contracts\MediaFileStore;
use App\Enums\PostPurgeOutcome;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class PruneDeletedUsers extends Command
{
    /** @var string */
    protected $signature = 'users:prune-deleted';

    /** @var string */
    protected $description = 'Permanently deletes soft-deleted accounts (and their remaining files) past the retention window.';

    public function handle(PurgePost $purgePost, MediaFileStore $files): int
    {
        $cutoff = now()->subDays((int) config('social.account_retention_days', 30));
        $purgedUsers = 0;
        $busyPosts = 0;
        $failedUsers = 0;

        foreach (User::onlyTrashed()->where('deleted_at', '<', $cutoff)->select('id')->lazyById(100) as $candidate) {
            $user = User::onlyTrashed()->find($candidate->id);

            if (! $user) {
                continue;
            }

            try {
                $canDeleteUser = true;

                foreach ($user->posts()->onlyTrashed()->select('posts.id')->lazyById(100) as $post) {
                    $outcome = $purgePost($post->id);

                    if ($outcome === PostPurgeOutcome::Busy) {
                        $busyPosts++;
                        $canDeleteUser = false;
                    }
                }

                if (! $canDeleteUser) {
                    continue;
                }

                $files->deletePaths($user->avatar_path ? [$user->avatar_path] : []);
                $user->notifications()->delete();
                $user->tokens()->delete();
                $user->forceDelete();
                $purgedUsers++;
            } catch (Throwable $exception) {
                $failedUsers++;
                report($exception);
                $this->error("Failed to purge account #{$user->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Account purge complete: {$purgedUsers} purged, {$busyPosts} posts busy, {$failedUsers} accounts failed.");

        return $failedUsers > 0 ? self::FAILURE : self::SUCCESS;
    }
}
