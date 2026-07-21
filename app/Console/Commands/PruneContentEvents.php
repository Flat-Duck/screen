<?php

namespace App\Console\Commands;

use App\Models\ContentEvent;
use Illuminate\Console\Command;

class PruneContentEvents extends Command
{
    protected $signature = 'analytics:prune-content-events';

    protected $description = 'Delete raw content analytics events beyond their retention window.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('social.analytics.raw_event_retention_days', 90));
        $deleted = 0;

        do {
            $ids = ContentEvent::query()->where('received_at', '<', $cutoff)->limit(1000)->pluck('id');
            $count = $ids->isEmpty() ? 0 : ContentEvent::query()->whereKey($ids)->delete();
            $deleted += $count;
        } while ($count === 1000);

        $this->info("Pruned {$deleted} expired content events.");

        return self::SUCCESS;
    }
}
