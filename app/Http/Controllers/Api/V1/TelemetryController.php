<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Telemetry\IngestTelemetryBatch;
use App\Http\Requests\StoreTelemetryEventsRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class TelemetryController extends Controller
{
    public function events(StoreTelemetryEventsRequest $request, IngestTelemetryBatch $ingestBatch): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $acceptedEventIds = $ingestBatch($device, $request->toData());

        return response()->json(['accepted_event_ids' => $acceptedEventIds]);
    }
}
