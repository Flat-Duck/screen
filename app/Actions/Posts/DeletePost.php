<?php

namespace App\Actions\Posts;

use App\Actions\Media\SyncPublicMedia;
use App\Models\Post;

class DeletePost
{
    public function __construct(private readonly SyncPublicMedia $publicMedia) {}

    public function __invoke(Post $post): void
    {
        $post->archived_at = null;
        $post->save();
        $post->delete();
        // A soft-deleted post is not publicly cacheable, so its CDN copies must go with it —
        // the bytes outliving the delete is exactly what a user would not expect.
        $this->publicMedia->forPost($post);
    }
}
