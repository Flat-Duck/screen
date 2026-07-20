<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class FollowRequestAcceptedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $user) {}

    /** @return array<int, class-string|non-empty-string> */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /** @return array<string, int|string|null> */
    public function toArray(object $notifiable): array
    {
        return ['user_id' => $this->user->id, 'username' => $this->user->username];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Follow request accepted',
            'body' => "{$this->user->name} accepted your follow request",
            'data' => ['type' => 'follow_request_accepted', 'user_id' => (string) $this->user->id],
        ];
    }

    public function settingsKey(): string
    {
        return 'follow_requests';
    }
}
