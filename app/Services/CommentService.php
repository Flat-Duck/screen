<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;

class CommentService
{
    public function addComment(User $user, Post $post, string $body): Comment
    {
        return $post->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
        ]);
    }

    public function deleteComment(Comment $comment): void
    {
        $comment->delete();
    }

    /** @return CursorPaginator<int, Comment> */
    public function commentsForPost(Post $post, int $perPage = 20): CursorPaginator
    {
        return $post->comments()
            ->with('user')
            ->oldest('id')
            ->cursorPaginate($perPage);
    }
}
