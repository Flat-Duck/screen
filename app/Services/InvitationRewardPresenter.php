<?php

namespace App\Services;

use App\Models\PointTransaction;
use App\Models\User;

final class InvitationRewardPresenter
{
    /** @return array{credited: true, points: int, inviter: array{name: string, username: string|null}|null}|null */
    public function forInvitee(User $user): ?array
    {
        $reward = PointTransaction::query()
            ->with('userInvite.inviter')
            ->where('user_id', $user->getKey())
            ->where('reason', PointTransaction::REASON_INVITEE_WELCOME_BONUS)
            ->latest('id')
            ->first();

        if ($reward === null) {
            return null;
        }

        $inviter = $reward->userInvite?->inviter;

        return [
            'credited' => true,
            'points' => $reward->amount,
            'inviter' => $inviter === null ? null : [
                'name' => $inviter->name,
                'username' => $inviter->username,
            ],
        ];
    }
}
