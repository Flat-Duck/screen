<?php

namespace App\Data\Telemetry;

final readonly class RegisterDeviceData
{
    public function __construct(
        public string $deviceId,
        public ?string $manufacturer,
        public ?string $brand,
        public ?string $model,
        public ?string $osName,
        public ?string $osVersion,
        public ?int $sdkInt,
        public ?string $appVersionName,
        public ?int $appVersionCode,
    ) {}
}
