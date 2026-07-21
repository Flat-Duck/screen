<?php

namespace App\Actions\Posts;

use App\Models\Post;

class DeletePost
{
    public function __invoke(Post $post): void
    {
        $post->archived_at = null;
        $post->save();
        $post->delete();
    }
}
