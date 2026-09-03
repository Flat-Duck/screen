<?php

namespace App\Services\Moderation\Detectors;

use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertType;
use App\Models\ModerationCase;
use App\Models\Report;
use App\Services\Moderation\AlertDraft;
use Illuminate\Support\Facades\DB;

/**
 * "This target is getting hammered right now." Counts *recent* reports rather than a case's
 * lifetime `report_count`, so a target that accumulated 20 reports over six months does not
 * look like an active incident, and one that took 6 in ten minutes does.
 */
class ReportSpikeDetector implements AlertDetector
{
    public function name(): string
    {
        return 'report_spike';
    }

    /** @return iterable<int, AlertDraft> */
    public function detect(): iterable
    {
        $threshold = (int) config('moderation.alerts.report_spike.threshold', 5);
        $criticalThreshold = (int) config('moderation.alerts.report_spike.critical_threshold', 15);
        $windowMinutes = (int) config('moderation.alerts.report_spike.window_minutes', 60);
        $since = now()->subMinutes($windowMinutes);

        $spikes = Report::query()
            ->where('created_at', '>=', $since)
            ->select('reportable_type', 'reportable_id')
            ->selectRaw('count(*) as recent_reports')
            ->selectRaw('count(distinct reporter_id) as distinct_reporters')
            ->groupBy('reportable_type', 'reportable_id')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        foreach ($spikes as $spike) {
            /** @var string $type */
            $type = $spike->getAttribute('reportable_type');
            $id = (int) $spike->getAttribute('reportable_id');
            $count = (int) $spike->getAttribute('recent_reports');

            $case = ModerationCase::query()->where('open_key', hash('sha256', $type.':'.$id))->first();

            yield new AlertDraft(
                type: ModerationAlertType::ReportSpike,
                severity: $count >= $criticalThreshold ? ModerationAlertSeverity::Critical : ModerationAlertSeverity::Warning,
                title: sprintf('%d reports on %s #%d in %d minutes', $count, class_basename($type), $id, $windowMinutes),
                dedupeKey: $type.':'.$id,
                target: $case?->target,
                context: [
                    'target_type' => class_basename($type),
                    'target_id' => $id,
                    'recent_reports' => $count,
                    'distinct_reporters' => (int) $spike->getAttribute('distinct_reporters'),
                    'window_minutes' => $windowMinutes,
                ],
                moderationCase: $case,
            );
        }
    }
}
