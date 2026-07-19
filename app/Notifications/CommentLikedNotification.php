<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class CommentLikedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Comment $comment,
        private readonly User $liker,
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
            'comment_id' => $this->comment->id,
            'post_id' => $this->comment->post_id,
            'liker_id' => $this->liker->id,
            'liker_username' => $this->liker->username,
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New like',
            'body' => "{$this->liker->name} liked your comment",
            'data' => [
                'type' => 'comment_like',
                'post_id' => (string) $this->comment->post_id,
                'comment_id' => (string) $this->comment->id,
                'liker_id' => (string) $this->liker->id,
            ],
        ];
    }

    public function settingsKey(): string
    {
        return 'likes';
    }
}
