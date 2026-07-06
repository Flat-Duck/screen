<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PostLikedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly User $liker,
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
            'liker_id' => $this->liker->id,
            'liker_username' => $this->liker->username,
        ];
    }
}
