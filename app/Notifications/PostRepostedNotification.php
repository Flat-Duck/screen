<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Contracts\FcmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class PostRepostedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post $post,
        private readonly User $reposter,
        private readonly ?string $comment = null,
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
            'reposter_id' => $this->reposter->id,
            'reposter_username' => $this->reposter->username,
            'comment' => $this->comment ? Str::limit($this->comment, 140) : null,
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New repost',
            'body' => "{$this->reposter->name} reposted your post",
            'data' => [
                'type' => 'repost',
                'post_id' => (string) $this->post->id,
                'reposter_id' => (string) $this->reposter->id,
            ],
        ];
    }

    public function settingsKey(): string
    {
        return 'reposts';
    }
}
