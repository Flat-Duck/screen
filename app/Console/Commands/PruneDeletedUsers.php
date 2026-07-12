<?php

namespace App\Console\Commands;

use App\Actions\Accounts\PruneDeletedAccounts;
use Illuminate\Console\Command;

class PruneDeletedUsers extends Command
{
    /** @var string */
    protected $signature = 'users:prune-deleted';

    /** @var string */
    protected $description = 'Permanently deletes soft-deleted accounts (and their remaining files) past the retention window.';

    public function handle(PruneDeletedAccounts $prune): int
    {
        $cutoff = now()->subDays((int) config('social.account_retention_days', 30));
        $summary = $prune($cutoff);
        $this->info("Account purge complete: {$summary->purged} purged, {$summary->busy} busy, {$summary->failed} failed.");

        return $summary->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
