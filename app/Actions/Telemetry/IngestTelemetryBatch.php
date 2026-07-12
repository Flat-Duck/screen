<?php

namespace App\Actions\Telemetry;

use App\Data\Telemetry\TelemetryBatchData;
use App\Models\Device;
use App\Models\TelemetryEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ingests one batch of events/errors/crashes from an already-authenticated device — the
 * `device` block in the payload body is informational only (used to refresh app version /
 * last seen), never trusted for identity. Insertion is `firstOrCreate` keyed on
 * `event_uuid`, making resends after an ambiguous network failure safe.
 */
class IngestTelemetryBatch
{
    /** Max stack trace length stored — payload hygiene, matches the Android-side plan's own callout. */
    private const MAX_STACK_TRACE_LENGTH = 4000;

    /** @return array<int, string> Accepted event UUIDs, in submission order. */
    public function __invoke(Device $device, TelemetryBatchData $batch): array
    {
        $device->forceFill([
            'app_version_name' => $batch->appVersionName ?? $device->app_version_name,
            'app_version_code' => $batch->appVersionCode ?? $device->app_version_code,
            'last_seen_at' => now(),
        ])->save();

        return DB::transaction(function () use ($batch, $device) {
            $accepted = [];

            foreach ($batch->events as $eventData) {
                $event = TelemetryEvent::firstOrCreate(
                    ['event_uuid' => $eventData->eventUuid],
                    [
                        'device_id' => $device->id,
                        'kind' => $eventData->kind,
                        'name' => $eventData->name,
                        'occurred_at' => $eventData->occurredAt,
                        'received_at' => now(),
                        'extras' => $eventData->extras,
                        'breadcrumbs' => $eventData->breadcrumbs,
                        'error_tag' => $eventData->errorTag,
                        'exception_class' => $eventData->exceptionClass,
                        'error_message' => $eventData->errorMessage,
                        'stack_trace' => $eventData->stackTrace !== null
                            ? Str::limit($eventData->stackTrace, self::MAX_STACK_TRACE_LENGTH, '')
                            : null,
                        'thread_name' => $eventData->threadName,
                        'is_fatal' => $eventData->isFatal,
                    ]
                );

                $accepted[] = $event->event_uuid;
            }

            return $accepted;
        });
    }
}
