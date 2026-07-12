<?php

namespace App\Services;

use App\Models\DevicePushToken;
use App\Models\User;

class PushTokenService
{
    /**
     * Upserts on `fcm_token`, not `(user_id, fcm_token)` — a token is unique per
     * install/device, so if the same token resurfaces under a different account (device
     * re-installed, or a second person signs into the same device), it re-points to the
     * new owner rather than leaving a stale row pointed at whoever had it before.
     */
    public function register(User $user, string $fcmToken): void
    {
        DevicePushToken::query()->updateOrCreate(
            ['fcm_token' => $fcmToken],
            ['user_id' => $user->id, 'platform' => 'android'],
        );
    }

    /**
     * Scoped to the current user so one account can't remove another's push
     * registration by guessing/replaying a token value.
     */
    public function unregister(User $user, string $fcmToken): void
    {
        DevicePushToken::query()
            ->where('user_id', $user->id)
            ->where('fcm_token', $fcmToken)
            ->delete();
    }
}
