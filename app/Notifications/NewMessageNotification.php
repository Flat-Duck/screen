<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The push half of delivery — v1 has no persistent socket connection, so a client polls
 * `GET /conversations/{id}/messages?after=` while a thread is open and relies on this
 * (via FcmChannel) to wake up when backgrounded.
 */
class NewMessageNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Conversation $conversation,
        private readonly Message $message,
        private readonly User $sender,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'sender_id' => $this->sender->id,
            'sender_username' => $this->sender->username,
            'excerpt' => Str::limit($this->message->body, 140),
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->sender->name,
            'body' => Str::limit($this->message->body, 80),
            'data' => [
                'type' => 'message',
                'conversation_id' => (string) $this->conversation->id,
            ],
        ];
    }

    public function settingsKey(): string
    {
        return 'messages';
    }
}
