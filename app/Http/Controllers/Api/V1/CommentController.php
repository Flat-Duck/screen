<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Services\BlockService;
use App\Services\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommentController extends Controller
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly BlockService $blocks,
    ) {}

    public function index(Request $request, Post $post): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if ($this->blocks->isBlockedEitherWay($viewer, $post->user)) {
            abort(404);
        }

        return CommentResource::collection($this->comments->commentsForPost($post));
    }

    public function store(StoreCommentRequest $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->blocks->isBlockedEitherWay($user, $post->user)) {
            abort(403);
        }

        $comment = $this->comments->addComment($user, $post, $request->validated()['body']);
        $comment->load('user');

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $this->comments->deleteComment($comment);

        return response()->json(null, 204);
    }
}
