<?php

namespace App\Console\Commands;

use App\Jobs\ComputePostMediaPlaceholder;
use App\Models\PostMedia;
use Illuminate\Console\Command;

/**
 * Queues {@see ComputePostMediaPlaceholder} for media that predates the placeholder column.
 *
 * One-off, but safe to re-run: the job itself skips any row that already has a hash, so a second
 * pass over a partly-finished batch queues work that immediately no-ops rather than recomputing.
 */
class BackfillPostMediaPlaceholders extends Command
{
    /** @var string */
    protected $signature = 'media:backfill-placeholders
        {--chunk=500 : Rows to read per query}
        {--limit= : Stop after queueing this many, for feeding a live queue in controlled batches}';

    /** @var string */
    protected $description = 'Queues ThumbHash placeholder generation for post media that has none.';

    public function handle(): int
    {
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));
        $pending = PostMedia::query()->whereNull('thumbhash')->count();

        if ($pending === 0) {
            $this->info('Every post media row already has a placeholder.');

            return self::SUCCESS;
        }

        $queued = 0;

        PostMedia::query()
            ->whereNull('thumbhash')
            ->select('id')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (&$queued, $limit): bool {
                foreach ($rows as $row) {
                    if ($limit !== null && $queued >= $limit) {
                        return false;
                    }

                    ComputePostMediaPlaceholder::dispatch($row->id);
                    $queued++;
                }

                return true;
            });

        $this->info("Queued {$queued} of {$pending} row(s) needing a placeholder.");

        return self::SUCCESS;
    }
}
