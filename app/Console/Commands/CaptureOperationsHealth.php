<?php

namespace App\Console\Commands;

use App\Services\OperationsHealthService;
use Illuminate\Console\Command;

class CaptureOperationsHealth extends Command
{
    protected $signature = 'operations:capture-health';

    protected $description = 'Capture a bounded operational health snapshot';

    public function handle(OperationsHealthService $health): int
    {
        $health->capture();
        $this->components->info('Operational health snapshot captured.');

        return self::SUCCESS;
    }
}
