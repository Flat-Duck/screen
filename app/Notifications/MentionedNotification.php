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

/** Shared across post captions and comment bodies — the copy is nearly identical either way. */
class MentionedNotification extends Notification implements FcmNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Post|Comment $mentionable,
        private readonly User $mentioner,
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
            'mentionable_type' => $this->type(),
            'mentionable_id' => $this->mentionable->id,
            'post_id' => $this->mentionable instanceof Post ? $this->mentionable->id : $this->mentionable->post_id,
            'mentioner_id' => $this->mentioner->id,
            'mentioner_username' => $this->mentioner->username,
            'excerpt' => Str::limit($this->excerpt(), 140),
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'You were mentioned',
            'body' => "{$this->mentioner->name}: ".Str::limit($this->excerpt(), 80),
            'data' => [
                'type' => 'mention',
                'mentionable_type' => $this->type(),
                'mentionable_id' => (string) $this->mentionable->id,
            ],
        ];
    }

    public function settingsKey(): string
    {
        return 'mentions';
    }

    private function type(): string
    {
        return $this->mentionable instanceof Post ? 'post' : 'comment';
    }

    private function excerpt(): string
    {
        return $this->mentionable instanceof Post
            ? (string) $this->mentionable->caption
            : $this->mentionable->body;
    }
}
