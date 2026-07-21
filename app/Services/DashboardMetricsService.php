<?php

namespace App\Services;

use App\Enums\ModerationCaseStatus;
use App\Models\DailyProductMetric;
use App\Models\DailyUserActivity;
use App\Models\ModerationCase;
use App\Models\RetentionCohortMetric;
use Carbon\CarbonImmutable;

class DashboardMetricsService
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $latest = DailyProductMetric::query()->latest('metric_date')->firstOrNew();
        $windowStart = $today->subDays(6)->toDateString();
        $weekly = DailyProductMetric::query()->where('metric_date', '>=', $windowStart)->get();
        $impressions = (int) $weekly->sum('impressions');
        $rate = static fn (int $numerator): float => $impressions === 0 ? 0.0 : round(($numerator / $impressions) * 100, 2);
        $openCases = ModerationCase::query()->whereIn('status', [
            ModerationCaseStatus::Open->value,
            ModerationCaseStatus::Investigating->value,
        ]);
        $oldestCase = (clone $openCases)->oldest('created_at')->first();
        $retention = RetentionCohortMetric::query()->where('day_number', 1)->latest('activity_date')->first();
        $sessions = (int) ($latest->sessions_started ?? 0);
        $crashes = (int) ($latest->crashed_sessions ?? 0);

        return [
            'metricDate' => $latest->exists ? $latest->metric_date : null,
            'isPartial' => (bool) ($latest->is_partial ?? false),
            'dau' => (int) ($latest->daily_active_users ?? 0),
            'wau' => DailyUserActivity::query()->where('activity_date', '>=', $windowStart)->distinct()->count('user_id'),
            'registrations' => (int) ($latest->registrations ?? 0),
            'activeCreators' => (int) ($latest->active_creators ?? 0),
            'screenshotsPublished' => (int) ($latest->screenshots_published ?? 0),
            'openRate' => $rate((int) $weekly->sum('opens')),
            'saveRate' => $rate((int) $weekly->sum('saves')),
            'followRate' => $rate((int) $weekly->sum('follows')),
            'hideRate' => $rate((int) $weekly->sum('hides')),
            'reportRate' => $rate((int) $weekly->sum('reports')),
            'dayOneRetention' => $retention && $retention->cohort_size > 0
                ? round(($retention->retained_users / $retention->cohort_size) * 100, 2)
                : 0.0,
            'crashFreeSessions' => $sessions === 0 ? 100.0 : round((max(0, $sessions - $crashes) / $sessions) * 100, 2),
            'moderationBacklog' => (clone $openCases)->count(),
            'oldestModerationAgeHours' => $oldestCase ? (int) $oldestCase->created_at->diffInHours(now()) : 0,
            'trend' => DailyProductMetric::query()->where('metric_date', '>=', $today->subDays(13)->toDateString())
                ->orderBy('metric_date')->get(),
        ];
    }
}
