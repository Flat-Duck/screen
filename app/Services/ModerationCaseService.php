<?php

namespace App\Services;

use App\Actions\Accounts\SetUserActiveState;
use App\Enums\ModerationCasePriority;
use App\Enums\ModerationCaseStatus;
use App\Enums\UserModerationState;
use App\Enums\UserVisibilityState;
use App\Models\Comment;
use App\Models\ModerationCase;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModerationCaseService
{
    public function __construct(private readonly AdminAuditLogger $audit, private readonly SetUserActiveState $setActiveState) {}

    public function assign(ModerationCase $case, User $actor, ?User $assignee, string $reason): void
    {
        $this->requireReason($reason);
        $before = $case->only(['assigned_to', 'status']);
        $case->update(['assigned_to' => $assignee?->id, 'status' => $case->status === ModerationCaseStatus::Open ? ModerationCaseStatus::Investigating : $case->status]);
        $this->audit->record($actor, 'moderation_case.assigned', $case, $reason, $before, $case->only(array_keys($before)));
    }

    public function setPriority(ModerationCase $case, User $actor, ModerationCasePriority $priority, string $reason): void
    {
        $this->requireReason($reason);
        $before = $case->only(['priority']);
        $case->update(['priority' => $priority]);
        $this->audit->record($actor, 'moderation_case.priority_changed', $case, $reason, $before, $case->only(['priority']));
    }

    public function addNote(ModerationCase $case, User $actor, string $body): void
    {
        $this->requireReason($body);
        $note = $case->notes()->create(['author_id' => $actor->id, 'body' => $body]);
        $this->audit->record($actor, 'moderation_case.note_added', $case, 'Internal note #'.$note->id);
    }

    public function transition(ModerationCase $case, User $actor, ModerationCaseStatus $status, string $reason): void
    {
        $this->requireReason($reason);
        $allowed = match ($case->status) {
            ModerationCaseStatus::Open => [ModerationCaseStatus::Investigating, ModerationCaseStatus::Actioned, ModerationCaseStatus::Dismissed],
            ModerationCaseStatus::Investigating => [ModerationCaseStatus::Actioned, ModerationCaseStatus::Dismissed],
            default => [],
        };
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Invalid moderation case transition.']);
        }

        DB::transaction(function () use ($case, $actor, $status, $reason): void {
            $before = $case->only(['status', 'resolved_at', 'open_key']);
            $resolved = in_array($status, [ModerationCaseStatus::Actioned, ModerationCaseStatus::Dismissed], true);
            $case->update(['status' => $status, 'resolved_at' => $resolved ? now() : null, 'open_key' => $resolved ? null : $case->open_key]);
            $case->reports()->where('status', Report::STATUS_PENDING)->update([
                'status' => $status === ModerationCaseStatus::Dismissed ? Report::STATUS_DISMISSED : Report::STATUS_REVIEWED,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'resolution_note' => $reason,
            ]);
            $this->audit->record($actor, 'moderation_case.'.$status->value, $case, $reason, $before, $case->only(array_keys($before)));
        });
    }

    public function setRecommendationEligibility(Post $post, User $actor, bool $eligible, string $reason): void
    {
        $this->requireReason($reason);
        $before = $post->only(['recommendation_eligible', 'moderated_at', 'moderation_reason']);
        $post->forceFill(['recommendation_eligible' => $eligible, 'moderated_at' => now(), 'moderation_reason' => $reason])->save();
        $this->audit->record($actor, $eligible ? 'post.recommendation_restored' : 'post.recommendation_removed', $post, $reason, $before, $post->only(array_keys($before)));
    }

    public function removeContent(Post|Comment $content, User $actor, string $reason): void
    {
        $this->requireReason($reason);
        $before = ['deleted_at' => $content->getAttribute('deleted_at')];
        $content->delete();
        $this->audit->record($actor, 'content.removed', $content, $reason, $before, ['deleted_at' => $content->getAttribute('deleted_at')]);
    }

    public function restorePost(Post $post, User $actor, string $reason): void
    {
        $this->requireReason($reason);
        $before = ['deleted_at' => $post->deleted_at];
        $post->restore();
        $this->audit->record($actor, 'content.restored', $post, $reason, $before, ['deleted_at' => null]);
    }

    public function warn(User $user, ModerationCase $case, User $actor, string $reason): void
    {
        $this->requireReason($reason);
        $warning = DB::table('user_warnings')->insertGetId(['user_id' => $user->id, 'moderation_case_id' => $case->id, 'issued_by' => $actor->id, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($actor, 'user.warned', $user, $reason, null, ['warning_id' => $warning]);
    }

    public function suspend(User $user, User $actor, string $reason, bool $ban = false): void
    {
        $this->requireReason($reason);
        abort_if($user->is($actor), 422);
        $before = $user->only(['is_active', 'visibility_state', 'moderation_state', 'moderation_reason']);
        ($this->setActiveState)($user, false);
        $user->forceFill(['moderation_reason' => $reason]);
        if ($ban) {
            $user->forceFill(['moderation_state' => UserModerationState::Banned, 'visibility_state' => UserVisibilityState::Hidden]);
        }
        $user->save();
        $this->audit->record($actor, $ban ? 'user.banned' : 'user.suspended', $user, $reason, $before, $user->only(array_keys($before)));
    }

    public function authorOf(?Model $target): ?User
    {
        return match (true) {
            $target instanceof Post, $target instanceof Comment => $target->user,
            $target instanceof User => $target,
            default => null,
        };
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'A moderation reason is required.']);
        }
    }
}
