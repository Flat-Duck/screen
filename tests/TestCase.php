<?php

namespace Tests;

use App\Actions\Auth\StartDeviceSession;
use App\Data\Auth\DeviceSessionContext;
use App\Enums\LoginMethod;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /** @param list<string> $abilities */
    protected function authenticateDevice(?Device $device = null, array $abilities = ['device:manage', 'telemetry:write', 'push-token:write']): Device
    {
        $device ??= Device::factory()->create();
        Sanctum::actingAs($device, $abilities);

        return $device;
    }

    protected function startUserSession(User $user, ?Device $device = null, string $name = 'mobile'): IssuedAccessToken
    {
        $device ??= Device::factory()->create();

        return app(StartDeviceSession::class)(
            $user,
            $device,
            LoginMethod::Password,
            new DeviceSessionContext($name, '127.0.0.1', 'phpunit'),
        );
    }
}
