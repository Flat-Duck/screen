<?php

namespace App\Actions\Devices;

use App\Models\Device;

final class ClearDevicePushToken
{
    public function __invoke(
        Device $device,
        ?string $expectedFcmToken = null,
        ?int $expectedUserId = null,
        ?string $expectedSessionUuid = null,
    ): void {
        $query = $device->pushToken();
        if ($expectedFcmToken !== null) {
            $query->where('fcm_token', $expectedFcmToken);
        }
        if ($expectedUserId !== null) {
            $query->whereExists(fn ($currentDevice) => $currentDevice->selectRaw('1')
                ->from('devices')
                ->whereColumn('devices.id', 'device_push_tokens.device_id')
                ->where(fn ($account) => $account->whereNull('devices.user_id')
                    ->orWhere(fn ($sameUser) => $sameUser
                        ->where('devices.user_id', $expectedUserId)
                        ->whereExists(fn ($session) => $session->selectRaw('1')
                            ->from('device_sessions')
                            ->whereColumn('device_sessions.device_id', 'devices.id')
                            ->where('device_sessions.uuid', $expectedSessionUuid)
                            ->where('device_sessions.user_id', $expectedUserId)
                            ->whereNull('device_sessions.ended_at')))));
        }
        $query->delete();
    }
}
