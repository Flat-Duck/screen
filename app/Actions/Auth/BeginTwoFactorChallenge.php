<?php

namespace App\Actions\Auth;

use App\Data\Auth\DeviceSessionContext;
use App\Data\Auth\PendingLoginData;
use App\Enums\LoginMethod;
use App\Models\Device;
use App\Models\User;
use App\Services\Auth\TwoFactorRequired;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BeginTwoFactorChallenge
{
    public function __invoke(User $user, Device $device, LoginMethod $method, DeviceSessionContext $context): TwoFactorRequired
    {
        $challengeToken = Str::random(64);
        $pending = new PendingLoginData($user->id, $device->id, $method, $context, CarbonImmutable::now());
        Cache::put("two-factor-challenge:{$challengeToken}", $pending->toArray(), now()->addMinutes(5));

        return new TwoFactorRequired($challengeToken);
    }
}
