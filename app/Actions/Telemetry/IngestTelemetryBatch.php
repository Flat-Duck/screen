<?php

namespace App\Actions\Telemetry;

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

    /**
     * @param  array<string, mixed>  $validated  Validated StoreTelemetryEventsRequest data.
     * @return array<int, string> Accepted event UUIDs, in submission order.
     */
    public function __invoke(Device $device, array $validated): array
    {
        $device->forceFill([
            'app_version_name' => $validated['device']['app_version_name'] ?? $device->app_version_name,
            'app_version_code' => $validated['device']['app_version_code'] ?? $device->app_version_code,
            'last_seen_at' => now(),
        ])->save();

        return DB::transaction(function () use ($validated, $device) {
            $accepted = [];

            foreach ($validated['events'] as $eventData) {
                $error = $eventData['error'] ?? null;

                $event = TelemetryEvent::firstOrCreate(
                    ['event_uuid' => $eventData['event_id']],
                    [
                        'device_id' => $device->id,
                        'kind' => $eventData['kind'],
                        'name' => $eventData['name'],
                        'occurred_at' => $eventData['occurred_at'],
                        'received_at' => now(),
                        'extras' => $eventData['extras'] ?? [],
                        'breadcrumbs' => $eventData['breadcrumbs'] ?? [],
                        'error_tag' => $error['tag'] ?? null,
                        'exception_class' => $error['exception_class'] ?? null,
                        'error_message' => $error['message'] ?? null,
                        'stack_trace' => isset($error['stack_trace'])
                            ? Str::limit($error['stack_trace'], self::MAX_STACK_TRACE_LENGTH, '')
                            : null,
                        'thread_name' => $error['thread_name'] ?? null,
                        'is_fatal' => $error['is_fatal'] ?? null,
                    ]
                );

                $accepted[] = $event->event_uuid;
            }

            return $accepted;
        });
    }
}
