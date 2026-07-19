<?php

namespace App\Actions\Posts;

use App\Models\Post;

class UpdatePost
{
    public function __construct(private readonly SyncPostHashtags $syncHashtags) {}

    /** @param array{caption?: string|null} $data */
    public function __invoke(Post $post, array $data): Post
    {
        if (array_key_exists('caption', $data)) {
            $post->caption = $data['caption'];
            $post->edited_at = now();
            $post->save();

            // Hashtags derive from the caption, so an edit must re-sync them — not just
            // append new ones — the same action creation uses.
            ($this->syncHashtags)($post, $post->caption);
        }

        return $post;
    }
}
