<?php

namespace App\Actions\Telemetry;

use App\Models\Device;

final readonly class DeviceRegistration
{
    public function __construct(
        public Device $device,
        public string $token,
        public bool $isNewDevice,
    ) {}
}
