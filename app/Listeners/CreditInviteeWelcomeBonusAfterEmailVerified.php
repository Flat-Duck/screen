<?php

namespace App\Listeners;

use App\Actions\Auth\CreditInviteeWelcomeBonus;
use App\Services\PointRewardService;
use Illuminate\Auth\Events\Verified;

final class CreditInviteeWelcomeBonusAfterEmailVerified
{
    public function __construct(
        private readonly CreditInviteeWelcomeBonus $creditBonus,
        private readonly PointRewardService $rewards,
    ) {}

    public function handle(Verified $event): void
    {
        $this->rewards->awardOnce($event->user, PointRewardService::ACCOUNT_REGISTRATION, 'account-registration:'.$event->user->getKey());
        ($this->creditBonus)($event->user);
    }
}
