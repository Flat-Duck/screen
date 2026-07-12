<?php

namespace App\Actions\Posts;

use App\Models\Post;

class DeletePost
{
    public function __invoke(Post $post): void
    {
        $post->delete();
    }
}
