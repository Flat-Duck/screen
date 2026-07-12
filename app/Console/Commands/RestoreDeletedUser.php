<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class RestoreDeletedUser extends Command
{
    /** @var string */
    protected $signature = 'users:restore {id : The id of the soft-deleted user to restore}';

    /** @var string */
    protected $description = 'Support-only: restores a soft-deleted account within its retention window.';

    public function handle(): int
    {
        $user = User::onlyTrashed()->find((int) $this->argument('id'));

        if (! $user) {
            $this->error('No soft-deleted user with that id (already purged, never deleted, or not trashed).');

            return self::FAILURE;
        }

        // Only revives posts trashed *because* the account was deleted
        // (`account_deleted_at` — see AccountService::deleteAccount()) — a post the
        // user had deleted individually before ever deleting their account stays
        // trashed, since restoring the account isn't an undo for that separate choice.
        $restoredPosts = $user->posts()->onlyTrashed()->whereNotNull('account_deleted_at')->update([
            'deleted_at' => null,
            'account_deleted_at' => null,
        ]);

        $user->restore();

        $this->info("Restored account #{$user->id} ({$user->email}) and {$restoredPosts} post(s).");

        return self::SUCCESS;
    }
}
