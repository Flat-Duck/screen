<?php

namespace App\Actions\Auth;

use App\Data\Auth\DeviceSessionContext;
use App\Enums\LoginMethod;
use App\Enums\SessionEndReason;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\IssuedAccessToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class StartDeviceSession
{
    public function __construct(private readonly CloseDeviceSession $closeSession) {}

    public function __invoke(
        User $user,
        Device $device,
        LoginMethod $method,
        DeviceSessionContext $context,
        bool $isNewAccount = false,
        bool $twoFactorVerified = false,
    ): IssuedAccessToken {
        $lock = Cache::lock("device-session:{$device->id}", 30);

        if (! $lock->get()) {
            throw new RuntimeException('This device is already starting another session.');
        }

        try {
            return DB::transaction(function () use ($user, $device, $method, $context, $isNewAccount, $twoFactorVerified): IssuedAccessToken {
                $device = Device::query()->lockForUpdate()->findOrFail($device->id);
                $active = $device->sessions()->whereNull('ended_at')->first();

                if ($active) {
                    ($this->closeSession)($active, SessionEndReason::Replaced);
                }

                $device->forceFill(['user_id' => $user->id])->save();
                $session = DeviceSession::create([
                    'uuid' => (string) Str::uuid(),
                    'device_id' => $device->id,
                    'user_id' => $user->id,
                    'login_method' => $method,
                    'started_at' => now(),
                    'last_seen_at' => now(),
                    'two_factor_verified_at' => $twoFactorVerified ? now() : null,
                    'app_version_name' => $device->app_version_name,
                    'app_version_code' => $device->app_version_code,
                    'os_version' => $device->os_version,
                    'ip_address' => $context->ipAddress,
                    'user_agent' => $context->userAgent,
                ]);

                $issued = $user->createToken(
                    $context->deviceName,
                    ['user:*'],
                    now()->addDays((int) config('security.user_session_lifetime_days', 90)),
                );
                $session->forceFill(['personal_access_token_id' => $issued->accessToken->id])->save();

                return new IssuedAccessToken($user, $issued->plainTextToken, $session, $isNewAccount);
            });
        } finally {
            $lock->release();
        }
    }
}
