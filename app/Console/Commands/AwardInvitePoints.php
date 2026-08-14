<?php

namespace App\Console\Commands;

use App\Actions\Auth\AwardMaturedInvitePoints;
use Illuminate\Console\Command;

class AwardInvitePoints extends Command
{
    protected $signature = 'invites:award-points';

    protected $description = 'Credit inviters for invite redemptions that have passed the maturity window.';

    public function handle(AwardMaturedInvitePoints $award): int
    {
        $this->info("Matured {$award()} invite(s).");

        return self::SUCCESS;
    }
}
