<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MessageRequestReceivedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Conversation $conversation,
        private readonly User $sender,
    ) {}

    /** @return array<int, class-string|non-empty-string> */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /** @return array<string, int|string|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->sender->id,
            'sender_username' => $this->sender->username,
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New message request',
            'body' => "{$this->sender->name} wants to message you",
            'data' => [
                'type' => 'message_request',
                'conversation_id' => (string) $this->conversation->id,
            ],
        ];
    }

    public function settingsKey(): string
    {
        return 'message_requests';
    }
}
