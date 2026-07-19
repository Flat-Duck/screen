<?php

namespace App\Actions\Comments;

use App\Models\Comment;
use App\Models\Mention;
use App\Notifications\MentionedNotification;
use App\Services\BlockService;
use App\Services\MuteService;
use App\Support\MentionParser;

/**
 * Same diff-based re-sync shape as SyncPostMentions — comments aren't currently editable,
 * so every call here sees an empty $existingIds set today, but this stays correct without
 * changes if comment editing is ever added.
 */
class SyncCommentMentions
{
    public function __construct(
        private readonly MentionParser $parser,
        private readonly BlockService $blocks,
        private readonly MuteService $mutes,
    ) {}

    public function __invoke(Comment $comment, string $body): void
    {
        $mentionedUserIds = $this->parser->parse($body)
            ->pluck('id')
            ->reject(fn (int $id): bool => $id === $comment->user_id)
            ->unique()
            ->values();

        $existingIds = Mention::query()
            ->where('mentionable_type', Comment::class)
            ->where('mentionable_id', $comment->id)
            ->pluck('mentioned_user_id');

        $staleIds = $existingIds->diff($mentionedUserIds);

        if ($staleIds->isNotEmpty()) {
            Mention::query()
                ->where('mentionable_type', Comment::class)
                ->where('mentionable_id', $comment->id)
                ->whereIn('mentioned_user_id', $staleIds)
                ->delete();
        }

        foreach ($mentionedUserIds->diff($existingIds) as $userId) {
            $mention = Mention::create([
                'mentioner_id' => $comment->user_id,
                'mentioned_user_id' => $userId,
                'mentionable_type' => Comment::class,
                'mentionable_id' => $comment->id,
            ]);

            $this->notify($mention, $comment);
        }
    }

    private function notify(Mention $mention, Comment $comment): void
    {
        $mentionedUser = $mention->mentionedUser;
        $mentioner = $comment->user;

        if ($this->blocks->isBlockedEitherWay($mentionedUser, $mentioner)) {
            return;
        }

        if ($this->mutes->shouldNotify($mentionedUser, $mentioner)) {
            $mentionedUser->notify(new MentionedNotification($comment, $mentioner));
        }
    }
}
