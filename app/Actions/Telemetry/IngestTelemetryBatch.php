<?php

namespace App\Actions\Telemetry;

use App\Data\Telemetry\TelemetryBatchData;
use App\Models\Device;
use App\Models\DeviceSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Atomically refreshes device metadata and ingests an idempotent telemetry batch from
 * the authenticated device. User/session attribution is always resolved server-side.
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
                'os_version' => $batch->osVersion ?? $device->os_version,
                'last_seen_at' => now(),
            ])->save();

            $accepted = [];
            $sessionUuids = collect($batch->events)->pluck('sessionUuid')->filter()->unique()->values();
            $sessions = DeviceSession::query()
                ->where('device_id', $device->id)
                ->whereIn('uuid', $sessionUuids)
                ->get()
                ->keyBy('uuid');

            foreach ($batch->events as $eventData) {
                $session = $eventData->sessionUuid !== null ? $sessions->get($eventData->sessionUuid) : null;

                if ($session && ! $this->occurredDuringSession($eventData->occurredAt, $session)) {
                    $session = null;
                }

                $accepted[] = ($this->persistEvent)($device, $eventData, $batch, $session);
            }

            return $accepted;
        });
    }

    private function occurredDuringSession(CarbonImmutable $occurredAt, DeviceSession $session): bool
    {
        $start = $session->started_at->toImmutable()->subMinutes(5);
        $end = ($session->ended_at ?? now())->toImmutable()->addMinutes(5);

        return $occurredAt->betweenIncluded($start, $end);
    }
}
