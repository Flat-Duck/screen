<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class FollowRequestReceivedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $requester) {}

    /** @return array<int, class-string|non-empty-string> */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /** @return array<string, int|string|null> */
    public function toArray(object $notifiable): array
    {
        return ['requester_id' => $this->requester->id, 'requester_username' => $this->requester->username];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New follow request',
            'body' => "{$this->requester->name} requested to follow you",
            'data' => ['type' => 'follow_request', 'user_id' => (string) $this->requester->id],
        ];
    }

    public function settingsKey(): string
    {
        return 'follow_requests';
    }
}
