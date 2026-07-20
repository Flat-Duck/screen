<?php

namespace App\Services;

use App\Actions\Comments\SyncCommentMentions;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentRepliedNotification;
use App\Notifications\PostCommentedNotification;
use Illuminate\Pagination\CursorPaginator;

class CommentService
{
    public function __construct(
        private readonly MuteService $mutes,
        private readonly SyncCommentMentions $syncMentions,
        private readonly ContentFilterService $filters,
    ) {}

    /**
     * A reply (non-null $parentId) notifies the parent comment's author instead of the
     * post owner — matches how real reply threads work, and avoids spamming the post
     * owner with every nested reply they aren't otherwise part of.
     */
    public function addComment(User $user, Post $post, string $body, ?int $parentId = null): Comment
    {
        $comment = $post->comments()->create([
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'body' => $body,
        ]);

        $filteredForPostOwner = $this->filters->apply($comment, $user, $post->user, 'comment');

        if ($parentId !== null) {
            $this->notifyReply($post, $comment, $user, $parentId);
        } else {
            $this->notifyPostOwner($post, $comment, $user, $filteredForPostOwner);
        }

        ($this->syncMentions)($comment, $body);

        return $comment;
    }

    public function deleteComment(Comment $comment): void
    {
        $comment->delete();
    }

    /** @return CursorPaginator<int, Comment> */
    public function commentsForPost(Post $post, User $viewer, int $perPage = 20): CursorPaginator
    {
        return $post->comments()
            ->topLevel()
            ->with('user')
            ->withCount(['replies', 'likes'])
            ->withExists(['filterMatches as is_filtered' => fn ($query) => $query->where('user_id', $viewer->id)])
            ->oldest('id')
            ->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, Comment> */
    public function repliesFor(Comment $comment, User $viewer, int $perPage = 20): CursorPaginator
    {
        return $comment->replies()
            ->with('user')
            ->withCount('likes')
            ->withExists(['filterMatches as is_filtered' => fn ($query) => $query->where('user_id', $viewer->id)])
            ->oldest('id')
            ->cursorPaginate($perPage);
    }

    private function notifyReply(Post $post, Comment $reply, User $replier, int $parentId): void
    {
        $parent = Comment::query()->find($parentId);

        if ($parent === null || $replier->is($parent->user)) {
            return;
        }

        $filteredForParent = $this->filters->apply($reply, $replier, $parent->user, 'comment');

        if (! $filteredForParent && $this->mutes->shouldNotify($parent->user, $replier)) {
            $parent->user->notify(new CommentRepliedNotification($post, $reply, $parent, $replier));
        }
    }

    private function notifyPostOwner(Post $post, Comment $comment, User $commenter, bool $filtered): void
    {
        if (! $filtered && $commenter->isNot($post->user) && $this->mutes->shouldNotify($post->user, $commenter)) {
            $post->user->notify(new PostCommentedNotification($post, $comment, $commenter));
        }
    }
}
