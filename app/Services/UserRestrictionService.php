<?php

namespace App\Services;

use App\Actions\Auth\RevokeUserSessions;
use App\Enums\SessionEndReason;
use App\Enums\UserRestrictionType;
use App\Models\ModerationCase;
use App\Models\User;
use App\Models\UserRestriction;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class UserRestrictionService
{
    public function __construct(private readonly AdminAuditLogger $audit, private readonly RevokeUserSessions $revokeSessions) {}

    public function isRestricted(User $user, UserRestrictionType $type): bool
    {
        return $user->restrictions()->active()->where('type', $type)->exists();
    }

    public function enforce(User $user, UserRestrictionType $type): void
    {
        abort_if($this->isRestricted($user, $type), 403, "Your account is restricted from {$type->value}.");
    }

    public function create(User $user, User $actor, UserRestrictionType $type, string $reason, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null, ?ModerationCase $case = null): UserRestriction
    {
        $this->validate($user, $actor, $reason, $startsAt, $endsAt);
        $restriction = $user->restrictions()->create([
            'type' => $type, 'starts_at' => $startsAt ?? now(), 'ends_at' => $endsAt,
            'reason' => $reason, 'moderation_case_id' => $case?->id, 'created_by' => $actor->id,
        ]);
        if ($type === UserRestrictionType::Login && $restriction->starts_at->isPast()) {
            ($this->revokeSessions)($user, SessionEndReason::Revoked);
        }
        $this->audit->record($actor, 'user_restriction.created', $restriction, $reason, null, $restriction->only(['user_id', 'type', 'starts_at', 'ends_at', 'moderation_case_id']));

        return $restriction;
    }

    public function extend(UserRestriction $restriction, User $actor, CarbonInterface $endsAt, string $reason): void
    {
        $this->requireReason($reason);
        abort_if($restriction->revoked_at !== null || $endsAt->lessThanOrEqualTo(now()), 422);
        $before = $restriction->only(['ends_at']);
        $restriction->update(['ends_at' => $endsAt]);
        $this->audit->record($actor, 'user_restriction.extended', $restriction, $reason, $before, $restriction->only(['ends_at']));
    }

    public function revoke(UserRestriction $restriction, User $actor, string $reason): void
    {
        $this->requireReason($reason);
        if ($restriction->revoked_at !== null) {
            return;
        }
        $before = $restriction->only(['revoked_at', 'revoked_by', 'revocation_reason']);
        $restriction->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason]);
        $this->audit->record($actor, 'user_restriction.revoked', $restriction, $reason, $before, $restriction->only(array_keys($before)));
    }

    private function validate(User $user, User $actor, string $reason, ?CarbonInterface $startsAt, ?CarbonInterface $endsAt): void
    {
        abort_if($user->is($actor), 422);
        $this->requireReason($reason);
        if ($endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt ?? now())) {
            throw ValidationException::withMessages(['ends_at' => 'The restriction end must be after its start.']);
        }
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'A restriction reason is required.']);
        }
    }
}
