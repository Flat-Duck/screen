<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Requests\StoreTelemetryEventsRequest;
use App\Models\Device;
use App\Models\TelemetryEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelemetryController extends Controller
{
    /** Max stack trace length stored — payload hygiene, matches the Android-side plan's own callout. */
    private const MAX_STACK_TRACE_LENGTH = 4000;

    /**
     * First-run enrollment: creates a Device and issues it a fresh Sanctum token. No prior
     * auth is possible here for a genuinely new device_uuid — this is how a device gets a
     * token in the first place.
     *
     * Re-registering an *existing* device_uuid is a different story: without this check,
     * anyone who learns/guesses a device_uuid could silently steal that device's identity
     * by re-registering unauthenticated — the old token (if any) gets deleted and a fresh
     * one handed to the attacker instead. So rotating an existing device's token always
     * requires presenting that exact device's current token via Authorization: Bearer —
     * proof of possession, not just knowledge of the UUID. This holds even for a device
     * that currently has *no* live token (e.g. deliberately revoked by support after a
     * compromise, or its token expired): a tokenless device is not up for grabs by
     * whoever asks next, since that would let anyone silently reclaim exactly the kind of
     * device a revocation was meant to lock out. There is deliberately no unauthenticated
     * recovery path for an existing device_uuid that lost its token — a real reinstall
     * (see CLAUDE.md) produces a brand new device_uuid anyway, which registers fine.
     */
    public function register(RegisterDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $device = Device::firstOrNew(['device_uuid' => $validated['device_id']]);
        $isNewDevice = ! $device->exists;

        if ($device->exists) {
            $authenticated = $request->user('sanctum');

            abort_unless(
                $authenticated instanceof Device && $authenticated->is($device),
                401,
                'This device is already registered. Re-registering it requires the current device token.',
            );
        }

        $device->fill([
            'manufacturer' => $validated['manufacturer'] ?? null,
            'brand' => $validated['brand'] ?? null,
            'model' => $validated['model'] ?? null,
            'os_name' => $validated['os_name'] ?? 'Android',
            'os_version' => $validated['os_version'] ?? null,
            'sdk_int' => $validated['sdk_int'] ?? null,
            'app_version_name' => $validated['app_version_name'] ?? null,
            'app_version_code' => $validated['app_version_code'] ?? null,
            'last_seen_at' => now(),
        ]);
        if ($isNewDevice) {
            $device->first_seen_at = now();
        }
        $device->save();

        // Sanctum tokens are hashed at rest and never retrievable again after creation, so
        // re-registering (e.g. app data cleared, or a fresh install with the same device_uuid
        // somehow) always revokes whatever existed before and mints a new one.
        $device->tokens()->delete();
        $token = $device->createToken('android-device')->plainTextToken;

        return response()->json([
            'device_id' => $device->id,
            'token' => $token,
        ], $isNewDevice ? 201 : 200);
    }

    /**
     * Ingests one batch of events/errors/crashes from the already-authenticated device (via
     * auth:sanctum — the device_id in the payload body is informational only, never trusted for
     * identity). Idempotent on event_uuid: a resent batch after an ambiguous network failure
     * inserts nothing twice.
     */
    public function events(StoreTelemetryEventsRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $validated = $request->validated();

        $device->forceFill([
            'app_version_name' => $validated['device']['app_version_name'] ?? $device->app_version_name,
            'app_version_code' => $validated['device']['app_version_code'] ?? $device->app_version_code,
            'last_seen_at' => now(),
        ])->save();

        $acceptedEventIds = DB::transaction(function () use ($validated, $device) {
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

        return response()->json(['accepted_event_ids' => $acceptedEventIds]);
    }
}
