<?php

namespace App\Console\Commands;

use App\Actions\Telemetry\PruneTelemetry as PruneTelemetryAction;
use Illuminate\Console\Command;

class PruneTelemetry extends Command
{
    protected $signature = 'telemetry:prune';

    protected $description = 'Delete telemetry events older than the configured diagnostic retention window.';

    public function handle(PruneTelemetryAction $prune): int
    {
        $cutoff = now()->subDays((int) config('telemetry.retention_days', 90));
        $this->info("Deleted {$prune($cutoff)} expired telemetry events.");

        return self::SUCCESS;
    }
}
