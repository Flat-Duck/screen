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

        // Also restores every post soft-deleted alongside the account (see
        // AccountService::deleteAccount()) — a full account restore implies restoring
        // its content too. Any post the user had already soft-deleted on their own
        // *before* deleting the account is indistinguishable from one that was only
        // soft-deleted as part of the account deletion, so it comes back too. This is a
        // rare, support-only tool, not a general-purpose per-post undo — documented
        // limitation, not a gap to fix later.
        $user->posts()->onlyTrashed()->restore();
        $user->restore();

        $this->info("Restored account #{$user->id} ({$user->email}).");

        return self::SUCCESS;
    }
}
