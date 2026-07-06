<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * The comment's author, or the owner of the post it's on, may delete it.
     *
     * Resolves the post `withTrashed()` since `Post` is soft-deletable — a comment on an
     * already soft-deleted post must still be deletable by the (former) post owner.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $user->id === $comment->user_id
            || $user->id === $comment->post()->withTrashed()->first()?->user_id;
    }
}
