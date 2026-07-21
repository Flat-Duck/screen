<?php

namespace App\Services;

use App\Enums\CrashGroupStatus;
use App\Models\CrashGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrashTriageService
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function assign(CrashGroup $group, User $actor, ?User $assignee, string $reason): void
    {
        $this->reason($reason);
        if ($assignee && ! $assignee->hasAdminPermission('telemetry.view')) {
            throw ValidationException::withMessages(['assignee' => 'The assignee must have telemetry access.']);
        }
        $before = $group->only(['assigned_to', 'status']);
        $group->update(['assigned_to' => $assignee?->id, 'status' => $assignee && $group->status === CrashGroupStatus::Open ? CrashGroupStatus::Investigating : $group->status]);
        $this->audit->record($actor, 'crash_group.assigned', $group, $reason, $before, $group->only(array_keys($before)));
    }

    public function transition(CrashGroup $group, User $actor, CrashGroupStatus $status, string $reason, ?string $fixedVersion): void
    {
        $this->reason($reason);
        $allowed = match ($group->status) {
            CrashGroupStatus::Open => [CrashGroupStatus::Investigating, CrashGroupStatus::Resolved, CrashGroupStatus::Ignored],
            CrashGroupStatus::Investigating => [CrashGroupStatus::Open, CrashGroupStatus::Resolved, CrashGroupStatus::Ignored],
            CrashGroupStatus::Resolved, CrashGroupStatus::Ignored => [CrashGroupStatus::Open],
        };
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid crash-group transition.']);
        }
        $fixedVersion = trim((string) $fixedVersion);
        if (mb_strlen($fixedVersion) > 255) {
            throw ValidationException::withMessages(['fixedVersion' => 'The fixed version may not exceed 255 characters.']);
        }

        DB::transaction(function () use ($group, $actor, $status, $reason, $fixedVersion): void {
            $before = $group->only(['status', 'fixed_app_version', 'resolved_at']);
            $group->update([
                'status' => $status,
                'fixed_app_version' => $status === CrashGroupStatus::Resolved && $fixedVersion !== '' ? $fixedVersion : ($status === CrashGroupStatus::Resolved ? $group->fixed_app_version : null),
                'resolved_at' => in_array($status, [CrashGroupStatus::Resolved, CrashGroupStatus::Ignored], true) ? now() : null,
            ]);
            $this->audit->record($actor, 'crash_group.'.$status->value, $group, $reason, $before, $group->only(array_keys($before)));
        });
    }

    public function addNote(CrashGroup $group, User $actor, string $body): void
    {
        $this->reason($body);
        if (mb_strlen($body) > 5000) {
            throw ValidationException::withMessages(['note' => 'Notes may not exceed 5,000 characters.']);
        }
        $note = $group->notes()->create(['author_id' => $actor->id, 'body' => trim($body)]);
        $this->audit->record($actor, 'crash_group.note_added', $group, 'Internal note #'.$note->id);
    }

    private function reason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
    }
}
