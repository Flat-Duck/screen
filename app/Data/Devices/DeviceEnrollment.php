<?php

namespace App\Data\Devices;

use App\Models\Device;

final readonly class DeviceEnrollment
{
    public function __construct(
        public Device $device,
        public string $token,
        public bool $isNewDevice,
    ) {}
}
