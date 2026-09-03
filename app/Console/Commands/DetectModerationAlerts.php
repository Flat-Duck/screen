<?php

namespace App\Console\Commands;

use App\Services\Moderation\Detectors\AlertDetector;
use App\Services\Moderation\ModerationAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs every registered detector and raises alerts. Detectors are isolated from one another:
 * one throwing (a bad query, Redis gone) is logged and skipped rather than aborting the run,
 * because a half-working alerting system beats a silent one — the failure mode that matters
 * here is "nobody was told", not "one rule was missing".
 */
class DetectModerationAlerts extends Command
{
    /** @var string */
    protected $signature = 'moderation:detect-alerts {--detector= : Run only the named detector}';

    /** @var string */
    protected $description = 'Evaluates moderation alert rules and raises alerts for anything that trips.';

    public function handle(ModerationAlertService $alerts): int
    {
        if (! (bool) config('moderation.alerts.enabled', true)) {
            $this->info('Moderation alert detection is disabled.');

            return self::SUCCESS;
        }

        /** @var list<class-string<AlertDetector>> $configured */
        $configured = config('moderation.alerts.detectors', []);
        $only = $this->option('detector');
        $raised = 0;
        $failed = 0;

        foreach ($configured as $class) {
            $detector = app($class);

            if (! $detector instanceof AlertDetector) {
                $this->warn(sprintf('%s does not implement AlertDetector — skipped.', $class));

                continue;
            }

            if (is_string($only) && $only !== '' && $detector->name() !== $only) {
                continue;
            }

            try {
                foreach ($detector->detect() as $draft) {
                    $alert = $alerts->raise($draft);
                    $raised++;
                    $this->line(sprintf('  [%s] %s (alert #%d)', $alert->severity->value, $alert->title, $alert->id));
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('Moderation alert detector failed.', [
                    'detector' => $detector->name(),
                    'exception' => $e->getMessage(),
                ]);
                $this->warn(sprintf('Detector %s failed: %s', $detector->name(), $e->getMessage()));
            }
        }

        $this->info(sprintf('Raised or refreshed %d alert(s); %d detector(s) failed.', $raised, $failed));

        // A failed detector is reported but does not fail the scheduled run — the scheduler
        // retrying the whole sweep would re-raise everything that already succeeded.
        return self::SUCCESS;
    }
}
