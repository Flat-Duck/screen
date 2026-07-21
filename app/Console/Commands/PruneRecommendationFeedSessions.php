<?php

namespace App\Console\Commands;

use App\Models\RecommendationFeedSession;
use Illuminate\Console\Command;

class PruneRecommendationFeedSessions extends Command
{
    protected $signature = 'recommendations:prune-sessions';

    protected $description = 'Deletes expired recommendation feed snapshots.';

    public function handle(): int
    {
        $deleted = RecommendationFeedSession::query()->where('expires_at', '<=', now())->delete();
        $this->info("Deleted {$deleted} expired recommendation feed session(s).");

        return self::SUCCESS;
    }
}
