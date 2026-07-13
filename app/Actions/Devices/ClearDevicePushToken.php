<?php

namespace App\Actions\Devices;

use App\Models\Device;

final class ClearDevicePushToken
{
    public function __invoke(Device $device): void
    {
        $device->pushToken()->delete();
    }
}
