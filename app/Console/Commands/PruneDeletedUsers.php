<?php

namespace App\Console\Commands;

use App\Actions\Posts\PurgePost;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneDeletedUsers extends Command
{
    /** @var string */
    protected $signature = 'users:prune-deleted';

    /** @var string */
    protected $description = 'Permanently deletes soft-deleted accounts (and their remaining files) past the retention window.';

    public function handle(PurgePost $purgePost): int
    {
        $cutoff = now()->subDays((int) config('social.account_retention_days', 30));

        $pending = User::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();

        $disk = Storage::disk(config('social.media_disk'));

        foreach ($pending as $user) {
            // AccountService::deleteAccount() already soft-deleted these at request time,
            // but purge them here too rather than trusting posts:prune-deleted got to
            // them first — forceDelete()'ing the user below cascades any still-present
            // post rows at the DB level, which would leak their media files otherwise
            // (same reasoning as PurgePost's own doc comment).
            $trashedPosts = $user->posts()->onlyTrashed()->with('media')->get();
            foreach ($trashedPosts as $post) {
                $purgePost($post);
            }

            if ($user->avatar_path) {
                $disk->delete($user->avatar_path);
            }

            // Polymorphic, so not FK-cascaded by forceDelete() below.
            $user->notifications()->delete();
            $user->tokens()->delete();

            $user->forceDelete();
        }

        $this->info("Purged {$pending->count()} account(s) past the retention window.");

        return self::SUCCESS;
    }
}
