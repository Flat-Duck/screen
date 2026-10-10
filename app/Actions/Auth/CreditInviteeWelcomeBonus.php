<?php

namespace App\Actions\Auth;

use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserInvite;
use App\Services\PointRewardService;
use Illuminate\Support\Facades\DB;

/** Credits an invitee's welcome points once their email is verified. */
final class CreditInviteeWelcomeBonus
{
    public function __construct(private readonly PointRewardService $rewards) {}

    public function __invoke(User $invitee): ?PointTransaction
    {
        return DB::transaction(function () use ($invitee): ?PointTransaction {
            $invite = UserInvite::query()
                ->where('invitee_user_id', $invitee->getKey())
                ->lockForUpdate()
                ->first();

            if ($invite === null) {
                return null;
            }

            $existing = PointTransaction::query()
                ->where('user_invite_id', $invite->getKey())
                ->where('reason', PointTransaction::REASON_INVITEE_WELCOME_BONUS)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $configuration = $this->rewards->configuration(PointRewardService::INVITEE_REGISTRATION);
            $points = $configuration['points'];
            if (! $configuration['enabled'] || $points < 1) {
                return null;
            }

            $transaction = PointTransaction::query()->create([
                'user_id' => $invitee->getKey(),
                'amount' => $points,
                'reason' => PointTransaction::REASON_INVITEE_WELCOME_BONUS,
                'user_invite_id' => $invite->getKey(),
            ]);
            User::query()->whereKey($invitee->getKey())->increment('points_balance', $points);

            return $transaction;
        });
    }
}
