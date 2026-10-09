<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\InviteReservation;
use App\Models\User;
use App\Models\UserInvite;
use App\Models\UserInviteLink;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Str;
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

    /**
     * A UX layer only — see InviteReservation's own kdoc. Does not, and cannot, introduce any
     * scarcity the underlying `users.invite_code` referral system doesn't already have: two
     * different reservations for the same code are both perfectly valid, since the code itself
     * stays unlimited-use. This is purely "the invite-gate screen already confirmed this code, so
     * the signup screen a minute later doesn't have to ask again."
     */
    public function reserve(?string $code, ?string $token = null): InviteReservation
    {
        $normalized = $token !== null ? $this->resolveTokenToCode($token) : strtoupper(trim((string) $code));
        $inviter = User::query()->where('invite_code', $normalized)->first();

        if ($inviter === null) {
            throw ValidationException::withMessages([
                'invite_code' => [__('That invite code is not valid.')],
            ]);
        }

        return InviteReservation::create([
            'code' => $normalized,
            'ticket' => Str::random(40),
            'expires_at' => now()->addMinutes((int) config('social.invite_reservation_ttl_minutes')),
        ]);
    }

    /** Creates a share URL while retaining only a SHA-256 hash of its bearer-like token. */
    public function createShareLink(User $inviter): string
    {
        do {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
        } while (UserInviteLink::query()->where('token_hash', $hash)->exists());

        UserInviteLink::query()->create([
            'inviter_user_id' => $inviter->id,
            'token_hash' => $hash,
        ]);

        return rtrim((string) config('social.canonical_url'), '/').'/invite/'.$token;
    }

    /** Resolves an opaque invite token to the inviter's current legacy code. */
    public function resolveTokenToCode(string $token): string
    {
        $validShape = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
        $link = $validShape
            ? UserInviteLink::query()->where('token_hash', hash('sha256', $token))->first()
            : null;
        $code = $link?->inviter?->invite_code;

        if ($code === null) {
            throw ValidationException::withMessages([
                'invite_token' => [__('That invite is not valid.')],
            ]);
        }

        return $code;
    }

    /**
     * Resolves a reservation ticket back to its code — null for a missing or expired ticket,
     * never an exception, since the caller (AuthController) needs to distinguish "no ticket sent"
     * from "ticket present but invalid" to fall back to the legacy raw `invite_code` field
     * correctly. An expired ticket is deliberately treated the same as a missing one here; the
     * field-specific "your invite session expired" error is the caller's responsibility.
     */
    public function resolveTicket(string $ticket): ?string
    {
        return InviteReservation::query()
            ->where('ticket', $ticket)
            ->where('expires_at', '>', now())
            ->first()
            ?->code;
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
