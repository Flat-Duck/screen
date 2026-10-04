<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Devices\ClearDevicePushToken;
use App\Actions\Devices\SetDevicePushToken;
use App\Http\Requests\DeletePushTokenRequest;
use App\Http\Requests\StorePushTokenRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

class PushTokenController extends Controller
{
    public function store(StorePushTokenRequest $request, SetDevicePushToken $setPushToken): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $setPushToken($device, $request->string('fcm_token')->toString());

        return response()->json(null, 204);
    }

    public function destroy(DeletePushTokenRequest $request, ClearDevicePushToken $clearPushToken): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $sessionId = $request->validated('session_id');
        $expectedSession = $sessionId
            ? $device->sessions()->where('uuid', $sessionId)->first()
            : null;
        if ($sessionId && ! $expectedSession) {
            return response()->json(null, 204);
        }

        // Account deletion ends its sessions before the Android client can make this
        // best-effort request. Keep the session's former user as the expected owner; the
        // conditional device check still protects a subsequent login on this installation.
        $clearPushToken(
            $device,
            $request->validated('fcm_token'),
            $expectedSession?->user_id,
            $expectedSession?->uuid,
        );

        return response()->json(null, 204);
    }
}
