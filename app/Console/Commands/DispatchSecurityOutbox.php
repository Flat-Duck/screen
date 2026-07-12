<?php

namespace App\Console\Commands;

use App\Actions\Security\DispatchPendingSecurityOutbox;
use Illuminate\Console\Command;

class DispatchSecurityOutbox extends Command
{
    protected $signature = 'security-outbox:dispatch';

    protected $description = 'Dispatch pending and recover stale security email outbox messages.';

    public function handle(DispatchPendingSecurityOutbox $dispatch): int
    {
        $this->info("Dispatched {$dispatch()} security outbox messages.");

        return self::SUCCESS;
    }
}
