<?php

namespace App\Data\Telemetry;

final readonly class TelemetryBatchData
{
    /** @param list<TelemetryEventData> $events */
    public function __construct(
        public string $reportedDeviceUuid,
        public ?string $appVersionName,
        public ?int $appVersionCode,
        public array $events,
    ) {}
}
