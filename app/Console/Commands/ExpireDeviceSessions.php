<?php

namespace App\Console\Commands;

use App\Actions\Auth\ExpireDeviceSessions as ExpireDeviceSessionsAction;
use Illuminate\Console\Command;

class ExpireDeviceSessions extends Command
{
    protected $signature = 'sessions:expire';

    protected $description = 'Close device sessions whose user access tokens have expired or disappeared.';

    public function handle(ExpireDeviceSessionsAction $expire): int
    {
        $this->info("Expired {$expire()} device sessions.");

        return self::SUCCESS;
    }
}
