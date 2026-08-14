<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Models\UserInvite;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;

/**
 * Gates registration on a valid invite code and records successful redemptions.
 * `registration.invite_only` (see FeatureConfigurationService) controls whether a code is
 * *required* to sign up at all — a code is always redeemable and always credits its owner
 * regardless of that flag's state; the flag only ever changes whether omitting one is allowed.
 */
class InviteCodeService
{
    private const FLAG_KEY = 'registration.invite_only';

    private const DEFAULT_POINTS_PER_INVITE = 50;

    private const DEFAULT_MATURITY_DAYS = 7;

    public function isRequired(): bool
    {
        // Absent flag row (never configured by an admin) defaults to NOT required — gating
        // registration by default with no explicit admin action would be a dangerous surprise
        // for anyone who hasn't set this up yet, not a safe default.
        return $this->flag()?->isActive() ?? false;
    }

    public function pointsPerInvite(): int
    {
        return (int) ($this->flag()?->payload['points_per_invite'] ?? self::DEFAULT_POINTS_PER_INVITE);
    }

    public function maturityDays(): int
    {
        return (int) ($this->flag()?->payload['maturity_days'] ?? self::DEFAULT_MATURITY_DAYS);
    }

    /**
     * Resolves a submitted invite code to its owning inviter. Throws (field: invite_code) when
     * the code was required — invite-only mode is active — but missing, or present but doesn't
     * resolve to a real user's code. Returns null only when the code was genuinely optional
     * (invite-only inactive) and omitted.
     */
    public function resolveOrFail(?string $code): ?User
    {
        $code = $code !== null ? trim($code) : null;

        if ($code === null || $code === '') {
            if ($this->isRequired()) {
                throw ValidationException::withMessages([
                    'invite_code' => [__('An invite code is required to sign up right now.')],
                ]);
            }

            return null;
        }

        $inviter = User::query()->where('invite_code', strtoupper($code))->first();

        if ($inviter === null) {
            throw ValidationException::withMessages([
                'invite_code' => [__('That invite code is not valid.')],
            ]);
        }

        return $inviter;
    }

    public function redeem(User $inviter, User $invitee, string $code): UserInvite
    {
        return UserInvite::create([
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'code_used' => strtoupper(trim($code)),
            'redeemed_at' => now(),
        ]);
    }

    /** @return CursorPaginator<int, UserInvite> */
    public function myInvites(User $inviter, int $perPage = 20): CursorPaginator
    {
        return UserInvite::query()
            ->where('inviter_user_id', $inviter->id)
            ->with('invitee')
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    private function flag(): ?FeatureFlag
    {
        return FeatureFlag::query()->where('key', self::FLAG_KEY)->first();
    }
}
