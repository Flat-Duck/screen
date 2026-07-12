<?php

namespace App\Actions\Telemetry;

use App\Data\Telemetry\TelemetryBatchData;
use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * Ingests one batch of events/errors/crashes from an already-authenticated device — the
 * `device` block in the payload body is informational only (used to refresh app version /
 * last seen), never trusted for identity. Insertion is `firstOrCreate` keyed on
 * `event_uuid`, making resends after an ambiguous network failure safe.
 */
class IngestTelemetryBatch
{
    public function __construct(private readonly PersistTelemetryEvent $persistEvent) {}

    /** @return array<int, string> Accepted event UUIDs, in submission order. */
    public function __invoke(Device $device, TelemetryBatchData $batch): array
    {
        return DB::transaction(function () use ($batch, $device) {
            $device->forceFill([
                'app_version_name' => $batch->appVersionName ?? $device->app_version_name,
                'app_version_code' => $batch->appVersionCode ?? $device->app_version_code,
                'last_seen_at' => now(),
            ])->save();

            $accepted = [];

            foreach ($batch->events as $eventData) {
                $accepted[] = ($this->persistEvent)($device, $eventData);
            }

            return $accepted;
        });
    }
}
