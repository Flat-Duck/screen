<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostCommentedNotification;
use Illuminate\Pagination\CursorPaginator;

class CommentService
{
    public function addComment(User $user, Post $post, string $body): Comment
    {
        $comment = $post->comments()->create([
            'user_id' => $user->id,
            'body' => $body,
        ]);

        if ($user->isNot($post->user)) {
            $post->user->notify(new PostCommentedNotification($post, $comment, $user));
        }

        return $comment;
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
