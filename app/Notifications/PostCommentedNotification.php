<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class PostCommentedNotification extends Notification implements ShouldQueue
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
        return ['database'];
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
}
