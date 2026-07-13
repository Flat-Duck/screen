<?php

namespace App\Data\Devices;

final readonly class EnrollDeviceData
{
    public function __construct(
        public string $deviceUuid,
        public ?string $manufacturer,
        public ?string $brand,
        public ?string $model,
        public string $osName,
        public ?string $osVersion,
        public ?int $sdkInt,
        public ?string $appVersionName,
        public ?int $appVersionCode,
    ) {}
}
