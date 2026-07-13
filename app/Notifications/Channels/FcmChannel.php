<?php

namespace App\Notifications\Channels;

use App\Models\DevicePushToken;
use App\Models\User;
use App\Notifications\Contracts\FcmNotification;
use App\Services\Fcm\FcmClient;
use App\Services\SettingsService;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * A custom notification channel (referenced by FQCN in a Notification's via(), Laravel's
 * standard mechanism for one-off channels — no service-provider registration needed).
 * Push is a nice-to-have layered on top of the existing database notifications, so this
 * fails open the same way social login's avatar fetch and the trending feed's Redis
 * calls do: unconfigured credentials, a notifiable with no registered devices, or any
 * per-token send failure are all silently skipped rather than surfacing as an error —
 * this must never be what breaks a like/comment/follow from recording. Failures are
 * still reported for operations, while an invalid response removes only that device's
 * token.
 */
class FcmChannel
{
    public function __construct(
        private readonly FcmClient $fcm,
        private readonly SettingsService $settings,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof FcmNotification || ! $notifiable instanceof User) {
            return;
        }

        if (! $this->fcm->isConfigured()) {
            return;
        }

        if (! $this->settings->pushNotificationsEnabledFor($notifiable, $notification->settingsKey())) {
            return;
        }

        $tokens = DevicePushToken::query()
            ->whereHas('device', fn ($query) => $query->where('user_id', $notifiable->id))
            ->pluck('fcm_token', 'id');

        if ($tokens->isEmpty()) {
            return;
        }

        $payload = $notification->toFcm($notifiable);

        foreach ($tokens as $tokenId => $fcmToken) {
            try {
                $result = $this->fcm->send($fcmToken, $payload['title'], $payload['body'], $payload['data'] ?? []);

                if ($result === 'invalid_token') {
                    DevicePushToken::destroy($tokenId);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
