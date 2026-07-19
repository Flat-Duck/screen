<?php

namespace App\Services;

use App\Models\Report;
use App\Models\User;

class ModerationService
{
    /**
     * Idempotent — reporting the same target twice returns the existing report rather than
     * erroring, backed by the (reporter_id, reportable_type, reportable_id) unique constraint.
     */
    public function report(User $reporter, string $reportableTypeAlias, int $reportableId, string $reason, ?string $details): Report
    {
        $reportableClass = Report::REPORTABLE_TYPES[$reportableTypeAlias];

        return Report::query()->firstOrCreate(
            [
                'reporter_id' => $reporter->id,
                'reportable_type' => $reportableClass,
                'reportable_id' => $reportableId,
            ],
            [
                'reason' => $reason,
                'details' => $details,
                'status' => Report::STATUS_PENDING,
            ],
        );
    }

    /** Marks a report as reviewed — the report was looked at and, typically, acted on. */
    public function markReviewed(Report $report, User $admin, ?string $note = null): Report
    {
        $report->update([
            'status' => Report::STATUS_REVIEWED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'resolution_note' => $note,
        ]);

        return $report;
    }

    /** Marks a report as dismissed — looked at, no action warranted. */
    public function dismiss(Report $report, User $admin, ?string $note = null): Report
    {
        $report->update([
            'status' => Report::STATUS_DISMISSED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'resolution_note' => $note,
        ]);

        return $report;
    }
}
