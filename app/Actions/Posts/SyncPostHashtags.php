<?php

namespace App\Actions\Posts;

use App\Models\Hashtag;
use App\Models\Post;

class SyncPostHashtags
{
    /**
     * Always syncs (never returns early on "nothing to tag") so this is safe to re-invoke
     * on an edited caption — a caption that dropped its hashtags entirely must clear the
     * post's existing tags, not leave them stale from the original caption.
     */
    public function __invoke(Post $post, ?string $caption): void
    {
        if (! $caption) {
            $post->hashtags()->sync([]);

            return;
        }

        preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches);

        $names = collect($matches[1])
            ->map(fn (string $tag): string => Hashtag::normalize($tag))
            ->unique()
            ->values();

        $hashtagIds = $names->map(
            fn (string $name): int => Hashtag::query()->firstOrCreate(['name' => $name])->id
        );

        $post->hashtags()->sync($hashtagIds);
    }
}
