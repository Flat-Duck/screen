<?php

namespace App\Http\Requests;

use App\Models\TelemetryEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTelemetryEventsRequest extends FormRequest
{
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
