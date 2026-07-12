<?php

namespace App\Console\Commands;

use App\Actions\Media\CleanOrphanedMedia as CleanOrphanedMediaAction;
use Illuminate\Console\Command;

class CleanOrphanedMedia extends Command
{
    protected $signature = 'media:clean-orphans';

    protected $description = 'Retry cleanup of abandoned post media staging directories.';

    public function handle(CleanOrphanedMediaAction $clean): int
    {
        $summary = $clean();
        $this->info("Media cleanup complete: {$summary->purged} deleted, {$summary->alreadyGone} referenced, {$summary->failed} failed.");

        return $summary->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
