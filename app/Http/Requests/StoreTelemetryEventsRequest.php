<?php

namespace App\Http\Requests;

use App\Data\Telemetry\TelemetryBatchData;
use App\Data\Telemetry\TelemetryEventData;
use App\Enums\TelemetryKind;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTelemetryEventsRequest extends FormRequest
{
    public function toData(): TelemetryBatchData
    {
        $data = $this->validated();
        $app = $data['app'];

        $events = array_map(static function (array $event): TelemetryEventData {
            $error = isset($event['error']) && is_array($event['error']) ? $event['error'] : [];

            return new TelemetryEventData(
                eventUuid: (string) $event['event_id'],
                sessionUuid: isset($event['session_id']) ? (string) $event['session_id'] : null,
                kind: TelemetryKind::from((string) $event['kind']),
                name: (string) $event['name'],
                occurredAt: CarbonImmutable::parse((string) $event['occurred_at']),
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
            appVersionName: isset($app['version_name']) ? (string) $app['version_name'] : null,
            appVersionCode: isset($app['version_code']) ? (int) $app['version_code'] : null,
            buildType: isset($app['build_type']) ? (string) $app['build_type'] : null,
            osVersion: isset($data['os_version']) ? (string) $data['os_version'] : null,
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
            'app' => ['required', 'array'],
            'app.version_name' => ['nullable', 'string', 'max:255'],
            'app.version_code' => ['nullable', 'integer'],
            'app.build_type' => ['nullable', 'string', 'max:50'],
            'os_version' => ['nullable', 'string', 'max:255'],

            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.session_id' => ['nullable', 'uuid'],
            'events.*.kind' => ['required', 'string', 'in:'.implode(',', array_column(TelemetryKind::cases(), 'value'))],
            'events.*.name' => ['required', 'string', 'max:100'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.extras' => ['nullable', 'array'],
            'events.*.breadcrumbs' => ['nullable', 'array', 'max:50'],
            'events.*.breadcrumbs.*.ts' => ['required_with:events.*.breadcrumbs', 'string'],
            'events.*.breadcrumbs.*.type' => ['required_with:events.*.breadcrumbs', 'string'],
            'events.*.breadcrumbs.*.name' => ['required_with:events.*.breadcrumbs', 'string'],
            'events.*.breadcrumbs.*.extras' => ['nullable', 'array'],

            'events.*.error' => ['nullable', 'array'],
            'events.*.error.tag' => ['required_with:events.*.error', 'string'],
            'events.*.error.exception_class' => ['required_with:events.*.error', 'string'],
            'events.*.error.message' => ['nullable', 'string', 'max:2000'],
            'events.*.error.stack_trace' => ['required_with:events.*.error', 'string'],
            'events.*.error.thread_name' => ['required_with:events.*.error', 'string'],
            'events.*.error.is_fatal' => ['required_with:events.*.error', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function ($validator): void {
            foreach ((array) $this->input('events', []) as $index => $event) {
                $context = json_encode([
                    'extras' => $event['extras'] ?? null,
                    'breadcrumbs' => $event['breadcrumbs'] ?? null,
                ]);

                if (is_string($context) && strlen($context) > 16_384) {
                    $validator->errors()->add("events.{$index}.extras", __('Diagnostic context may not exceed 16 KB.'));
                }
            }
        }];
    }
}
