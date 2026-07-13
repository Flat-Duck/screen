<?php

namespace App\Data\Auth;

use App\Enums\LoginMethod;
use Carbon\CarbonImmutable;

final readonly class PendingLoginData
{
    public function __construct(
        public int $userId,
        public int $deviceId,
        public LoginMethod $loginMethod,
        public DeviceSessionContext $context,
        public CarbonImmutable $initiatedAt,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'login_method' => $this->loginMethod->value,
            'device_name' => $this->context->deviceName,
            'ip_address' => $this->context->ipAddress,
            'user_agent' => $this->context->userAgent,
            'initiated_at' => $this->initiatedAt->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: (int) $data['user_id'],
            deviceId: (int) $data['device_id'],
            loginMethod: LoginMethod::from((string) $data['login_method']),
            context: new DeviceSessionContext(
                deviceName: (string) $data['device_name'],
                ipAddress: isset($data['ip_address']) ? (string) $data['ip_address'] : null,
                userAgent: isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            ),
            initiatedAt: CarbonImmutable::parse((string) $data['initiated_at']),
        );
    }
}
