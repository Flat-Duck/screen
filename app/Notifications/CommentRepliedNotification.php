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

/** Notifies the parent comment's author, not the post owner — see CommentService::addComment. */
class CommentRepliedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly Comment $reply,
        private readonly Comment $parent,
        private readonly User $replier,
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
            'comment_id' => $this->reply->id,
            'parent_comment_id' => $this->parent->id,
            'replier_id' => $this->replier->id,
            'replier_username' => $this->replier->username,
            'excerpt' => Str::limit($this->reply->body, 140),
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New reply',
            'body' => "{$this->replier->name}: ".Str::limit($this->reply->body, 80),
            'data' => [
                'type' => 'comment_reply',
                'post_id' => (string) $this->post->id,
                'comment_id' => (string) $this->reply->id,
                'parent_comment_id' => (string) $this->parent->id,
            ],
        ];
    }

    public function settingsKey(): string
    {
        return 'comments';
    }
}
