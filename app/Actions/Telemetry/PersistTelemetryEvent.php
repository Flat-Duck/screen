<?php

namespace App\Actions\Telemetry;

use App\Data\Telemetry\TelemetryEventData;
use App\Models\Device;
use App\Models\TelemetryEvent;
use Illuminate\Support\Str;

class PersistTelemetryEvent
{
    private const MAX_STACK_TRACE_LENGTH = 4000;

    public function __invoke(Device $device, TelemetryEventData $data): string
    {
        $event = TelemetryEvent::firstOrCreate(
            ['event_uuid' => $data->eventUuid],
            [
                'device_id' => $device->id,
                'kind' => $data->kind,
                'name' => $data->name,
                'occurred_at' => $data->occurredAt,
                'received_at' => now(),
                'extras' => $data->extras,
                'breadcrumbs' => $data->breadcrumbs,
                'error_tag' => $data->errorTag,
                'exception_class' => $data->exceptionClass,
                'error_message' => $data->errorMessage,
                'stack_trace' => $data->stackTrace !== null
                    ? Str::limit($data->stackTrace, self::MAX_STACK_TRACE_LENGTH, '')
                    : null,
                'thread_name' => $data->threadName,
                'is_fatal' => $data->isFatal,
            ]
        );

        return $event->event_uuid;
    }
}
