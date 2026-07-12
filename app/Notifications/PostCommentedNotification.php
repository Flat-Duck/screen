<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class PostCommentedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly Comment $comment,
        private readonly User $commenter,
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
            'post_id' => $this->post->id,
            'comment_id' => $this->comment->id,
            'commenter_id' => $this->commenter->id,
            'commenter_username' => $this->commenter->username,
            'excerpt' => Str::limit($this->comment->body, 140),
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New comment',
            'body' => "{$this->commenter->name}: ".Str::limit($this->comment->body, 80),
            'data' => [
                'type' => 'comment',
                'post_id' => (string) $this->post->id,
                'comment_id' => (string) $this->comment->id,
            ],
        ];
    }

    public function settingsKey(): string
    {
        return 'comments';
    }
}
