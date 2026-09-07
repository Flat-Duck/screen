<?php

namespace App\Console\Commands;

use App\Jobs\PublishPostMediaToCdn;
use App\Jobs\UnpublishPostMediaFromCdn;
use App\Models\PostMedia;
use Illuminate\Console\Command;

/**
 * Finds media whose published state no longer matches whether its post is public, and fixes it.
 *
 * Every path out of "public" — archive, delete, purge, going private, deactivation, moderation —
 * has to dispatch {@see UnpublishPostMediaFromCdn}, and every one of those is a place a future
 * change can forget. This is the net under all of them, and it is far cheaper than auditing them
 * by hand. Scheduled, not one-off.
 *
 * Reports without `--fix` so it can be run to answer "is anything wrong" without acting.
 */
class ReconcilePublicMedia extends Command
{
    /** @var string */
    protected $signature = 'media:reconcile-public
        {--fix : Dispatch the corrections instead of only reporting them}
        {--chunk=500 : Rows to read per query}';

    /** @var string */
    protected $description = 'Finds post media whose public CDN state disagrees with its post visibility.';

    public function handle(): int
    {
        if (! config('social.public_cdn.enabled')) {
            $this->info('Public CDN is disabled; nothing to reconcile.');

            return self::SUCCESS;
        }

        $fix = (bool) $this->option('fix');
        $exposed = 0;
        $missing = 0;

        PostMedia::query()
            ->with('post.user')
            ->whereNotNull('public_path')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (&$exposed, $fix): void {
                foreach ($rows as $media) {
                    if ($media->post?->isPubliclyCacheable()) {
                        continue;
                    }

                    $exposed++;
                    $this->warn("post_media {$media->id} is published but its post is no longer public.");

                    if ($fix) {
                        UnpublishPostMediaFromCdn::dispatch($media->id);
                    }
                }
            });

        // The opposite drift matters much less — a public post without a CDN copy is merely slow,
        // not exposed — but it is the same query and worth reporting while we are here.
        PostMedia::query()
            ->with('post.user')
            ->whereNull('public_path')
            ->whereNotNull('thumbnail_path')
            ->chunkById((int) $this->option('chunk'), function ($rows) use (&$missing, $fix): void {
                foreach ($rows as $media) {
                    if (! $media->post?->isPubliclyCacheable()) {
                        continue;
                    }

                    $missing++;

                    if ($fix) {
                        PublishPostMediaToCdn::dispatch($media->id);
                    }
                }
            });

        $this->info(sprintf(
            '%d wrongly published, %d publishable but not published.%s',
            $exposed,
            $missing,
            $fix ? ' Corrections dispatched.' : ' Re-run with --fix to correct.',
        ));

        // Non-zero when something was exposed and not corrected, so a scheduled run without --fix
        // surfaces as a failure rather than passing quietly.
        return $exposed > 0 && ! $fix ? self::FAILURE : self::SUCCESS;
    }
}
