<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Devices\EnrollDevice;
use App\Http\Requests\EnrollDeviceRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    public function enroll(EnrollDeviceRequest $request, EnrollDevice $enroll): JsonResponse
    {
        $principal = $request->user('sanctum');
        $result = $enroll($request->toData(), $principal instanceof Device ? $principal : null);

        return response()->json([
            'device_uuid' => $result->device->device_uuid,
            'token' => $result->token,
        ], $result->isNewDevice ? 201 : 200);
    }
}
