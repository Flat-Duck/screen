<?php

namespace App\Services\Moderation\Detectors;

use App\Enums\ModerationAlertSeverity;
use App\Enums\ModerationAlertType;
use App\Enums\ModerationCasePriority;
use App\Enums\ModerationCaseStatus;
use App\Models\ModerationCase;
use App\Services\Moderation\AlertDraft;

/**
 * The dashboard has always computed `oldestModerationAgeHours` and rendered it; this is the
 * half that was missing — something that fires when it crosses a line instead of waiting for
 * a moderator to notice. Ages from `created_at` (first report) rather than `last_reported_at`,
 * so a case that keeps attracting reports cannot quietly reset its own clock.
 */
class SlaBreachDetector implements AlertDetector
{
    public function name(): string
    {
        return 'sla_breach';
    }

    /** @return iterable<int, AlertDraft> */
    public function detect(): iterable
    {
        /** @var array<string, int> $hoursByPriority */
        $hoursByPriority = config('moderation.alerts.sla.hours', []);
        $criticalMultiplier = (float) config('moderation.alerts.sla.critical_multiplier', 2.0);
        $maxPerRun = (int) config('moderation.alerts.sla.max_per_run', 25);

        $cases = ModerationCase::query()
            ->whereIn('status', [ModerationCaseStatus::Open->value, ModerationCaseStatus::Investigating->value])
            ->orderBy('created_at')
            ->get();

        $raised = 0;

        foreach ($cases as $case) {
            if ($raised >= $maxPerRun) {
                return;
            }

            $budget = $hoursByPriority[$case->priority->value] ?? null;

            if ($budget === null || $case->created_at === null) {
                continue;
            }

            $ageHours = (int) $case->created_at->diffInHours(now());

            if ($ageHours < $budget) {
                continue;
            }

            $raised++;

            yield new AlertDraft(
                type: ModerationAlertType::SlaBreach,
                severity: $ageHours >= $budget * $criticalMultiplier
                    ? ModerationAlertSeverity::Critical
                    : ModerationAlertSeverity::Warning,
                title: sprintf(
                    '%s case #%d unresolved for %dh (budget %dh)',
                    ucfirst($case->priority->value),
                    $case->id,
                    $ageHours,
                    $budget,
                ),
                dedupeKey: 'case:'.$case->id,
                target: $case->target,
                context: [
                    'case_id' => $case->id,
                    'priority' => $case->priority->value,
                    'status' => $case->status->value,
                    'age_hours' => $ageHours,
                    'budget_hours' => $budget,
                    'assigned' => $case->assigned_to !== null,
                    'report_count' => $case->report_count,
                ],
                moderationCase: $case,
            );
        }
    }

    /** @return list<string> Priorities with a configured budget — used by the settings view. */
    public static function coveredPriorities(): array
    {
        /** @var array<string, int> $hours */
        $hours = config('moderation.alerts.sla.hours', []);

        return array_values(array_intersect(
            array_column(ModerationCasePriority::cases(), 'value'),
            array_keys($hours),
        ));
    }
}
