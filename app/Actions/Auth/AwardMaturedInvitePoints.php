<?php

namespace App\Actions\Auth;

use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserInvite;
use App\Services\InviteCodeService;
use Illuminate\Support\Facades\DB;

/**
 * Daily job (see routes/console.php): credits an inviter's points_balance once their invitee
 * has been redeemed for at least the configured maturity window AND is still a genuinely
 * active account at check time — a deliberately higher bar than "the invitee signed up," to
 * make farming this with throwaway accounts meaningfully harder than a single automatable
 * API call would be (see InviteCodeService's own kdoc for the "codes always redeemable,
 * points always deferred" split this enforces).
 */
final class AwardMaturedInvitePoints
{
    public function __construct(private readonly InviteCodeService $inviteCodes) {}

    public function __invoke(): int
    {
        $cutoff = now()->subDays($this->inviteCodes->maturityDays());
        $pointsPerInvite = $this->inviteCodes->pointsPerInvite();
        $matured = 0;

        UserInvite::query()
            ->whereNull('points_awarded_at')
            ->where('redeemed_at', '<=', $cutoff)
            ->with('invitee')
            ->chunkById(100, function ($invites) use ($pointsPerInvite, &$matured): void {
                foreach ($invites as $invite) {
                    /** @var UserInvite $invite */
                    $invitee = $invite->invitee;

                    // Soft-deleted invitees resolve to a null relation (SoftDeletes' own global
                    // scope); a non-null-but-not-publicly-visible invitee is deactivated/banned/
                    // suppressed. Either way: leave points_awarded_at null and re-check next run
                    // rather than permanently writing off the row — a reinstated account should
                    // still be able to mature later.
                    if ($invitee === null || ! $invitee->isPubliclyVisible()) {
                        continue;
                    }

                    DB::transaction(function () use ($invite, $pointsPerInvite): void {
                        PointTransaction::create([
                            'user_id' => $invite->inviter_user_id,
                            'amount' => $pointsPerInvite,
                            'reason' => PointTransaction::REASON_REFERRAL_BONUS,
                            'user_invite_id' => $invite->id,
                        ]);
                        User::query()->whereKey($invite->inviter_user_id)->increment('points_balance', $pointsPerInvite);
                        $invite->forceFill([
                            'points_awarded_at' => now(),
                            'points_awarded' => $pointsPerInvite,
                        ])->save();
                    });
                    $matured++;
                }
            });

        return $matured;
    }
}
