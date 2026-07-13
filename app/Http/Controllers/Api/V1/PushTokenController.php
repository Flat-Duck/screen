<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Devices\ClearDevicePushToken;
use App\Actions\Devices\SetDevicePushToken;
use App\Http\Requests\StorePushTokenRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    public function store(StorePushTokenRequest $request, SetDevicePushToken $setPushToken): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $setPushToken($device, $request->string('fcm_token')->toString());

        return response()->json(null, 204);
    }

    public function destroy(Request $request, ClearDevicePushToken $clearPushToken): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $clearPushToken($device);

        return response()->json(null, 204);
    }
}
