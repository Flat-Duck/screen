<?php

namespace App\Console\Commands;

use App\Models\InviteReservation;
use Illuminate\Console\Command;

class PruneInviteReservations extends Command
{
    protected $signature = 'invites:prune-reservations';

    protected $description = 'Deletes expired invite reservation tickets.';

    public function handle(): int
    {
        $deleted = InviteReservation::query()->where('expires_at', '<=', now())->delete();
        $this->info("Deleted {$deleted} expired invite reservation(s).");

        return self::SUCCESS;
    }
}
