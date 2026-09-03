<?php

namespace App\Services\Moderation\Detectors;

use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertType;
use App\Models\ModerationCase;
use App\Models\Report;
use App\Models\User;
use App\Services\Moderation\AlertDraft;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Catches campaigns whose individual reports all look reasonable. Two shapes, because
 * brigading runs in both directions:
 *
 *  - one account filing against many unrelated targets (a serial reporter weaponising the
 *    report button), and
 *  - many freshly-created accounts converging on one target (a raid to force a takedown).
 *
 * Note this is the one detector whose alerts often mean the *reporters* are the problem —
 * which is exactly why nothing here auto-actions the reported content.
 */
class BrigadingDetector implements AlertDetector
{
    public function name(): string
    {
        return 'brigading';
    }

    /** @return iterable<int, AlertDraft> */
    public function detect(): iterable
    {
        $since = now()->subHours((int) config('moderation.alerts.brigading.window_hours', 24));

        yield from $this->serialReporters($since);
        yield from $this->youngAccountRaids($since);
    }

    /** @return iterable<int, AlertDraft> */
    private function serialReporters(CarbonInterface $since): iterable
    {
        $threshold = (int) config('moderation.alerts.brigading.reporter_target_threshold', 8);

        $reporters = Report::query()
            ->where('created_at', '>=', $since)
            ->select('reporter_id')
            // Concatenated rather than `count(distinct (a, b))`: the row-constructor form is
            // Postgres-only and the test suite runs on SQLite.
            ->selectRaw("count(distinct reportable_type || ':' || reportable_id) as target_count")
            ->groupBy('reporter_id')
            ->havingRaw("count(distinct reportable_type || ':' || reportable_id) >= ?", [$threshold])
            ->get();

        foreach ($reporters as $row) {
            $reporterId = (int) $row->getAttribute('reporter_id');
            $targetCount = (int) $row->getAttribute('target_count');
            $reporter = User::query()->find($reporterId);

            if ($reporter === null) {
                continue;
            }

            yield new AlertDraft(
                type: ModerationAlertType::Brigading,
                severity: ModerationAlertSeverity::Warning,
                title: sprintf('@%s reported %d different targets', $reporter->username, $targetCount),
                dedupeKey: 'reporter:'.$reporterId,
                target: $reporter,
                context: [
                    'shape' => 'serial_reporter',
                    'reporter_id' => $reporterId,
                    'reporter_username' => $reporter->username,
                    'distinct_targets' => $targetCount,
                    'since' => $since->toIso8601String(),
                ],
            );
        }
    }

    /** @return iterable<int, AlertDraft> */
    private function youngAccountRaids(CarbonInterface $since): iterable
    {
        $threshold = (int) config('moderation.alerts.brigading.young_reporter_threshold', 4);
        $youngSince = now()->subDays((int) config('moderation.alerts.brigading.young_account_days', 7));

        $raids = Report::query()
            ->where('reports.created_at', '>=', $since)
            ->whereIn('reporter_id', User::query()->where('created_at', '>=', $youngSince)->select('id'))
            ->select('reportable_type', 'reportable_id')
            ->selectRaw('count(distinct reporter_id) as young_reporters')
            ->groupBy('reportable_type', 'reportable_id')
            ->havingRaw('count(distinct reporter_id) >= ?', [$threshold])
            ->orderByDesc(DB::raw('count(distinct reporter_id)'))
            ->get();

        foreach ($raids as $raid) {
            /** @var string $type */
            $type = $raid->getAttribute('reportable_type');
            $id = (int) $raid->getAttribute('reportable_id');
            $count = (int) $raid->getAttribute('young_reporters');
            $case = ModerationCase::query()->where('open_key', hash('sha256', $type.':'.$id))->first();

            yield new AlertDraft(
                type: ModerationAlertType::Brigading,
                severity: ModerationAlertSeverity::Critical,
                title: sprintf('%d accounts under a week old reported %s #%d', $count, class_basename($type), $id),
                dedupeKey: 'young-raid:'.$type.':'.$id,
                target: $case?->target,
                context: [
                    'shape' => 'young_account_raid',
                    'target_type' => class_basename($type),
                    'target_id' => $id,
                    'young_reporters' => $count,
                    'since' => $since->toIso8601String(),
                ],
                moderationCase: $case,
            );
        }
    }
}
