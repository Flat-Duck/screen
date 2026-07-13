<?php

namespace App\Actions\Devices;

use App\Models\Device;
use App\Models\DevicePushToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SetDevicePushToken
{
    public function __invoke(Device $device, string $fcmToken): void
    {
        Cache::lock('device-push-token:mutation', 10)->block(5, function () use ($device, $fcmToken): void {
            DB::transaction(function () use ($device, $fcmToken): void {
                DevicePushToken::query()->where('fcm_token', $fcmToken)->where('device_id', '!=', $device->id)->delete();
                DevicePushToken::query()->updateOrCreate(
                    ['device_id' => $device->id],
                    ['fcm_token' => $fcmToken, 'platform' => 'android'],
                );
            });
        });
    }
}
