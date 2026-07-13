<?php

namespace App\Data\Auth;

final readonly class DeviceSessionContext
{
    public function __construct(
        public string $deviceName,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
