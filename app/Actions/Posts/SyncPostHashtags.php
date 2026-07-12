<?php

namespace App\Actions\Posts;

use App\Models\Hashtag;
use App\Models\Post;

class SyncPostHashtags
{
    public function __invoke(Post $post, ?string $caption): void
    {
        if (! $caption) {
            return;
        }

        preg_match_all('/#([\p{L}\p{N}_]+)/u', $caption, $matches);

        $names = collect($matches[1])
            ->map(fn (string $tag): string => Hashtag::normalize($tag))
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        $hashtagIds = $names->map(
            fn (string $name): int => Hashtag::query()->firstOrCreate(['name' => $name])->id
        );

        $post->hashtags()->sync($hashtagIds);
    }
}
