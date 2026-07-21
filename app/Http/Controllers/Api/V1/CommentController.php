<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRestrictionType;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Services\BlockService;
use App\Services\CommentService;
use App\Services\InteractionPermissionService;
use App\Services\LikeService;
use App\Services\UserRestrictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommentController extends Controller
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly BlockService $blocks,
        private readonly LikeService $likes,
        private readonly InteractionPermissionService $interactions,
        private readonly UserRestrictionService $restrictions,
    ) {}

    public function index(Request $request, Post $post): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if ($this->blocks->isBlockedEitherWay($viewer, $post->user)) {
            abort(404);
        }

        abort_unless($post->isVisibleTo($viewer), 404);

        $comments = $this->comments->commentsForPost($post, $viewer);
        $this->likes->annotateCommentsAreLiked($comments->getCollection(), $viewer);

        return CommentResource::collection($comments);
    }

    public function store(StoreCommentRequest $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->restrictions->enforce($user, UserRestrictionType::Commenting);

        if ($this->blocks->isBlockedEitherWay($user, $post->user)) {
            abort(403);
        }

        abort_unless($post->isVisibleTo($user), 404);
        abort_unless($post->comments_enabled && $this->interactions->canComment($user, $post->user), 403);

        $validated = $request->validated();
        $comment = $this->comments->addComment($user, $post, $validated['body'], $validated['parent_id'] ?? null);
        $comment->load('user');
        $comment->is_liked = false;
        $comment->is_filtered = false;

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }

    public function replies(Request $request, Comment $comment): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if ($this->blocks->isBlockedEitherWay($viewer, $comment->post->user)) {
            abort(404);
        }

        abort_unless($comment->post->isVisibleTo($viewer), 404);

        $replies = $this->comments->repliesFor($comment, $viewer);
        $this->likes->annotateCommentsAreLiked($replies->getCollection(), $viewer);

        return CommentResource::collection($replies);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $this->comments->deleteComment($comment);

        return response()->json(null, 204);
    }
}
