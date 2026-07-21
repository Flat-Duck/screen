<?php

namespace App\Actions\Posts;

use App\Models\Post;

class UpdatePost
{
    public function __construct(
        private readonly SyncPostHashtags $syncHashtags,
        private readonly SyncPostMentions $syncMentions,
    ) {}

    /** @param array{caption?: string|null, comments_enabled?: bool, reposts_enabled?: bool, category_id?: int<0, max>|null, source_application?: string|null, source_url?: string|null, content_warning?: string|null} $data */
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

        if (array_key_exists('comments_enabled', $data)) {
            $post->comments_enabled = $data['comments_enabled'];
        }
        if (array_key_exists('reposts_enabled', $data)) {
            $post->reposts_enabled = $data['reposts_enabled'];
        }
        if (array_key_exists('category_id', $data)) {
            $post->category_id = $data['category_id'];
        }
        if (array_key_exists('source_application', $data)) {
            $post->source_application = $data['source_application'];
        }
        if (array_key_exists('source_url', $data)) {
            $post->source_url = $data['source_url'];
        }
        if (array_key_exists('content_warning', $data)) {
            $post->content_warning = $data['content_warning'];
        }

        if ($post->isDirty()) {
            $post->save();
        }

        return $post;
    }
}
