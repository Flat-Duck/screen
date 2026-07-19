<?php

namespace App\Actions\Posts;

use App\Models\Mention;
use App\Models\Post;
use App\Notifications\MentionedNotification;
use App\Services\BlockService;
use App\Services\MuteService;
use App\Support\MentionParser;

/**
 * Always re-syncs from scratch (diffing against existing rows) so this is safe to
 * re-invoke on an edited caption — only *newly added* mentions get notified, existing
 * ones aren't re-notified on every edit, and mentions dropped from the caption are removed.
 */
class SyncPostMentions
{
    public function __construct(
        private readonly MentionParser $parser,
        private readonly BlockService $blocks,
        private readonly MuteService $mutes,
    ) {}

    public function __invoke(Post $post, ?string $caption): void
    {
        $mentionedUserIds = $this->parser->parse($caption)
            ->pluck('id')
            ->reject(fn (int $id): bool => $id === $post->user_id)
            ->unique()
            ->values();

        $existingIds = Mention::query()
            ->where('mentionable_type', Post::class)
            ->where('mentionable_id', $post->id)
            ->pluck('mentioned_user_id');

        $staleIds = $existingIds->diff($mentionedUserIds);

        if ($staleIds->isNotEmpty()) {
            Mention::query()
                ->where('mentionable_type', Post::class)
                ->where('mentionable_id', $post->id)
                ->whereIn('mentioned_user_id', $staleIds)
                ->delete();
        }

        foreach ($mentionedUserIds->diff($existingIds) as $userId) {
            $mention = Mention::create([
                'mentioner_id' => $post->user_id,
                'mentioned_user_id' => $userId,
                'mentionable_type' => Post::class,
                'mentionable_id' => $post->id,
            ]);

            $this->notify($mention, $post);
        }
    }

    private function notify(Mention $mention, Post $post): void
    {
        $mentionedUser = $mention->mentionedUser;
        $mentioner = $post->user;

        if ($this->blocks->isBlockedEitherWay($mentionedUser, $mentioner)) {
            return;
        }

        if ($this->mutes->shouldNotify($mentionedUser, $mentioner)) {
            $mentionedUser->notify(new MentionedNotification($post, $mentioner));
        }
    }
}
