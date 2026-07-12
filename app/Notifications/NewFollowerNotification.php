<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewFollowerNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $follower) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'follower_id' => $this->follower->id,
            'follower_username' => $this->follower->username,
            'follower_name' => $this->follower->name,
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New follower',
            'body' => "{$this->follower->name} started following you",
            'data' => ['type' => 'follow', 'user_id' => (string) $this->follower->id],
        ];
    }

    public function settingsKey(): string
    {
        return 'follows';
    }
}
