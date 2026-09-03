<?php

namespace App\Services;

use App\Enums\HashtagModerationState;
use App\Models\Hashtag;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The tag-level equivalent of ModerationCaseService::setRecommendationEligibility(). Same
 * contract as every other moderation action in this app: a written reason is mandatory and
 * the change is audit-logged with before/after state.
 *
 * Deliberately does not touch the posts carrying the tag — moderating a tag removes its
 * discovery surface, and removing individual posts stays a separate, per-post decision.
 */
class HashtagModerationService
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function setState(Hashtag $hashtag, User $actor, HashtagModerationState $state, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'A moderation reason is required.']);
        }

        $before = $hashtag->only(['moderation_state', 'moderation_reason', 'moderated_by', 'moderated_at']);

        $hashtag->forceFill([
            'moderation_state' => $state,
            'moderation_reason' => $reason,
            'moderated_by' => $actor->getKey(),
            'moderated_at' => now(),
        ])->save();

        $this->audit->record(
            $actor,
            'hashtag.'.$state->value,
            $hashtag,
            $reason,
            $before,
            $hashtag->only(array_keys($before)),
        );
    }
}
