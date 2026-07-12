<?php

namespace App\Console\Commands;

use App\Actions\Accounts\RestoreDeletedAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RestoreDeletedUser extends Command
{
    /** @var string */
    protected $signature = 'users:restore {id : The id of the soft-deleted user to restore}';

    /** @var string */
    protected $description = 'Support-only: restores a soft-deleted account within its retention window.';

    public function handle(RestoreDeletedAccount $restoreAccount): int
    {
        try {
            $result = $restoreAccount((int) $this->argument('id'));
        } catch (ModelNotFoundException) {
            $this->error('No soft-deleted user with that id (already purged, never deleted, or not trashed).');

            return self::FAILURE;
        }

        $this->info("Restored account #{$result->user->id} ({$result->user->email}) and {$result->restoredPosts} post(s).");

        return self::SUCCESS;
    }
}
