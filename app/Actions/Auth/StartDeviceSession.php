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
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class StartDeviceSession
{
    public function __construct(private readonly CloseDeviceSession $closeSession) {}

    /**
     * The single choke point every login path (password, social, completed 2FA
     * challenge) funnels through before a token is ever issued — which is why the
     * deactivated-account check lives here rather than duplicated in each of those,
     * and why it reliably catches all of them.
     */
    public function __invoke(
        User $user,
        Device $device,
        LoginMethod $method,
        DeviceSessionContext $context,
        bool $isNewAccount = false,
        bool $twoFactorVerified = false,
    ): IssuedAccessToken {
        // Strict `=== false`, not a falsy check: a `User` just created in this same
        // request (registration, first social sign-in) never round-trips the DB's
        // `is_active` default (true) back into memory — Eloquent only re-fetches the
        // auto-increment id after an insert, nothing else — so it reads as `null` here,
        // not `true`. `! null` is truthy in PHP, which would reject every fresh
        // registration outright. `null` genuinely means "not confirmed inactive," so it
        // must pass; only a real `false` (an existing row explicitly deactivated) blocks.
        if ($user->is_active === false) {
            // Keyed 'account', not 'login' — this same check fires from register/social/
            // 2FA-challenge too, none of which have a 'login' field to attach the error to.
            throw ValidationException::withMessages([
                'account' => __('This account has been deactivated. Contact support if you believe this is a mistake.'),
            ]);
        }

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
