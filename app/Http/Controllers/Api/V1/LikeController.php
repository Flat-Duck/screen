<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Services\BlockService;
use App\Services\LikeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function __construct(
        private readonly LikeService $likes,
        private readonly BlockService $blocks,
    ) {}

    public function store(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->blocks->isBlockedEitherWay($user, $post->user)) {
            abort(403);
        }

        abort_unless($post->isVisibleTo($user), 404);

        $this->likes->like($user, $post);

        return response()->json(['likes_count' => $post->likes()->count()]);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($post->isVisibleTo($user), 404);

        $this->likes->unlike($user, $post);

        return response()->json(['likes_count' => $post->likes()->count()]);
    }

    public function storeComment(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Checked against the comment's author, not the post owner — that's who the
        // liker is actually interacting with here.
        if ($this->blocks->isBlockedEitherWay($user, $comment->user)) {
            abort(403);
        }

        abort_unless($comment->post->isVisibleTo($user), 404);

        $this->likes->like($user, $comment);

        return response()->json(['likes_count' => $comment->likes()->count()]);
    }

    public function destroyComment(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($comment->post->isVisibleTo($user), 404);

        $this->likes->unlike($user, $comment);

        return response()->json(['likes_count' => $comment->likes()->count()]);
    }
}
