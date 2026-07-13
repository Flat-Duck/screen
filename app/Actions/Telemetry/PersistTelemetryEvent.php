<?php

namespace App\Actions\Telemetry;

use App\Data\Telemetry\TelemetryBatchData;
use App\Data\Telemetry\TelemetryEventData;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\TelemetryEvent;
use App\Services\Telemetry\CrashFingerprint;
use App\Services\Telemetry\TelemetryRedactor;
use Illuminate\Support\Str;

class PersistTelemetryEvent
{
    private const MAX_STACK_TRACE_LENGTH = 4000;

    public function __construct(
        private readonly TelemetryRedactor $redactor,
        private readonly CrashFingerprint $fingerprint,
    ) {}

    public function __invoke(
        Device $device,
        TelemetryEventData $data,
        TelemetryBatchData $batch,
        ?DeviceSession $session,
    ): string {
        $stackTrace = $this->redactor->redactString($data->stackTrace);
        $exceptionClass = $this->redactor->redactString($data->exceptionClass);

        $event = TelemetryEvent::firstOrCreate(
            ['device_id' => $device->id, 'event_uuid' => $data->eventUuid],
            [
                'user_id' => $session?->user_id,
                'device_session_id' => $session?->id,
                'kind' => $data->kind->value,
                'name' => $data->name,
                'occurred_at' => $data->occurredAt,
                'received_at' => now(),
                'extras' => $this->redactor->redactArray($data->extras),
                'breadcrumbs' => $this->redactor->redactArray($data->breadcrumbs),
                'error_tag' => $this->redactor->redactString($data->errorTag),
                'exception_class' => $exceptionClass,
                'error_message' => Str::limit($this->redactor->redactString($data->errorMessage) ?? '', 2000, '') ?: null,
                'stack_trace' => $stackTrace !== null
                    ? Str::limit($stackTrace, self::MAX_STACK_TRACE_LENGTH, '')
                    : null,
                'thread_name' => $data->threadName,
                'is_fatal' => $data->isFatal,
                'app_version_name' => $batch->appVersionName,
                'app_version_code' => $batch->appVersionCode,
                'build_type' => $batch->buildType,
                'os_version' => $batch->osVersion,
                'crash_fingerprint' => $this->fingerprint->make($exceptionClass, $stackTrace),
            ]
        );

        return $event->event_uuid;
    }
}
