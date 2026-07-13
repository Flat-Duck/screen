<?php

namespace App\Actions\Telemetry;

use App\Models\TelemetryEvent;
use DateTimeInterface;

final class PruneTelemetry
{
    public function __invoke(DateTimeInterface $cutoff): int
    {
        $deleted = 0;

        foreach (TelemetryEvent::query()->where('received_at', '<', $cutoff)->select('id')->lazyById(500) as $event) {
            $deleted += TelemetryEvent::query()->whereKey($event->id)->delete();
        }

        return $deleted;
    }
}
