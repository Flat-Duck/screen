<?php

namespace App\Actions\Posts;

use App\Models\Post;

class UpdatePost
{
    public function __construct(
        private readonly SyncPostHashtags $syncHashtags,
        private readonly SyncPostMentions $syncMentions,
    ) {}

    /** @param array{caption?: string|null} $data */
    public function __invoke(Post $post, array $data): Post
    {
        if (array_key_exists('caption', $data)) {
            $post->caption = $data['caption'];
            $post->edited_at = now();
            $post->save();

            // Hashtags/mentions both derive from the caption, so an edit must re-sync
            // them — not just append new ones — the same actions creation uses. Mentions
            // only notify newly-added users, not everyone still mentioned (see
            // SyncPostMentions).
            ($this->syncHashtags)($post, $post->caption);
            ($this->syncMentions)($post, $post->caption);
        }

        return $post;
    }
}
