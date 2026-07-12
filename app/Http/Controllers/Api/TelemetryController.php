<?php

namespace App\Http\Controllers\Api;

use App\Actions\Telemetry\IngestTelemetryBatch;
use App\Actions\Telemetry\RegisterDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Requests\StoreTelemetryEventsRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class TelemetryController extends Controller
{
    public function register(RegisterDeviceRequest $request, RegisterDevice $registerDevice): JsonResponse
    {
        $principal = $request->user('sanctum');
        $authenticatedDevice = $principal instanceof Device ? $principal : null;
        $registration = $registerDevice($request->toData(), $authenticatedDevice);

        return response()->json([
            'device_id' => $registration->device->id,
            'token' => $registration->token,
        ], $registration->isNewDevice ? 201 : 200);
    }

    public function events(StoreTelemetryEventsRequest $request, IngestTelemetryBatch $ingestBatch): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $acceptedEventIds = $ingestBatch($device, $request->toData());

        return response()->json(['accepted_event_ids' => $acceptedEventIds]);
    }
}
