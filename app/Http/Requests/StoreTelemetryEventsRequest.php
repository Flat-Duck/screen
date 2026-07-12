<?php

namespace App\Http\Requests;

use App\Data\Telemetry\TelemetryBatchData;
use App\Data\Telemetry\TelemetryEventData;
use App\Models\TelemetryEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTelemetryEventsRequest extends FormRequest
{
    public function toData(): TelemetryBatchData
    {
        $data = $this->validated();
        $device = $data['device'];

        $events = array_map(static function (array $event): TelemetryEventData {
            $error = isset($event['error']) && is_array($event['error']) ? $event['error'] : [];

            return new TelemetryEventData(
                eventUuid: (string) $event['event_id'],
                kind: (string) $event['kind'],
                name: (string) $event['name'],
                occurredAt: (string) $event['occurred_at'],
                extras: isset($event['extras']) && is_array($event['extras']) ? $event['extras'] : [],
                breadcrumbs: isset($event['breadcrumbs']) && is_array($event['breadcrumbs']) ? array_values($event['breadcrumbs']) : [],
                errorTag: isset($error['tag']) ? (string) $error['tag'] : null,
                exceptionClass: isset($error['exception_class']) ? (string) $error['exception_class'] : null,
                errorMessage: isset($error['message']) ? (string) $error['message'] : null,
                stackTrace: isset($error['stack_trace']) ? (string) $error['stack_trace'] : null,
                threadName: isset($error['thread_name']) ? (string) $error['thread_name'] : null,
                isFatal: isset($error['is_fatal']) ? (bool) $error['is_fatal'] : null,
            );
        }, array_values($data['events']));

        return new TelemetryBatchData(
            reportedDeviceUuid: (string) $device['device_id'],
            appVersionName: isset($device['app_version_name']) ? (string) $device['app_version_name'] : null,
            appVersionCode: isset($device['app_version_code']) ? (int) $device['app_version_code'] : null,
            events: $events,
        );
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * Device identity is enforced by the auth:sanctum route middleware, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Mirrors the Android client's TelemetryBatchRequest/TelemetryEventPayload field names exactly.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device' => ['required', 'array'],
            'device.device_id' => ['required', 'uuid'],
            'device.manufacturer' => ['nullable', 'string', 'max:255'],
            'device.brand' => ['nullable', 'string', 'max:255'],
            'device.model' => ['nullable', 'string', 'max:255'],
            'device.os_name' => ['nullable', 'string', 'max:255'],
            'device.os_version' => ['nullable', 'string', 'max:255'],
            'device.sdk_int' => ['nullable', 'integer'],
            'device.app_version_name' => ['nullable', 'string', 'max:255'],
            'device.app_version_code' => ['nullable', 'integer'],

            'events' => ['required', 'array', 'min:1'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.kind' => ['required', 'string', 'in:'.implode(',', TelemetryEvent::KINDS)],
            'events.*.name' => ['required', 'string', 'max:100'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.extras' => ['nullable', 'array'],
            'events.*.breadcrumbs' => ['nullable', 'array'],
            'events.*.breadcrumbs.*.ts' => ['required_with:events.*.breadcrumbs', 'string'],
            'events.*.breadcrumbs.*.type' => ['required_with:events.*.breadcrumbs', 'string'],
            'events.*.breadcrumbs.*.name' => ['required_with:events.*.breadcrumbs', 'string'],
            'events.*.breadcrumbs.*.extras' => ['nullable', 'array'],

            'events.*.error' => ['nullable', 'array'],
            'events.*.error.tag' => ['required_with:events.*.error', 'string'],
            'events.*.error.exception_class' => ['required_with:events.*.error', 'string'],
            'events.*.error.message' => ['nullable', 'string'],
            'events.*.error.stack_trace' => ['required_with:events.*.error', 'string'],
            'events.*.error.thread_name' => ['required_with:events.*.error', 'string'],
            'events.*.error.is_fatal' => ['required_with:events.*.error', 'boolean'],
        ];
    }
}
