<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreRepostRequest;
use App\Http\Resources\RepostResource;
use App\Models\Post;
use App\Models\Repost;
use App\Models\User;
use App\Services\BlockService;
use App\Services\LikeService;
use App\Services\RepostService;
use App\Services\SavedPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RepostController extends Controller
{
    public function __construct(
        private readonly RepostService $reposts,
        private readonly BlockService $blocks,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function store(StoreRepostRequest $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->blocks->isBlockedEitherWay($user, $post->user)) {
            abort(403);
        }

        $this->reposts->repost($user, $post, $request->validated()['comment'] ?? null);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->reposts->unrepost($user, $post);

        return response()->json(null, 204);
    }

    public function forUser(Request $request, User $user): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if ($this->blocks->isBlockedEitherWay($viewer, $user)) {
            abort(404);
        }

        $reposts = $this->reposts->repostsFor($user);

        $posts = $reposts->getCollection()->map(fn (Repost $repost) => $repost->post);
        $this->likes->annotateIsLiked($posts, $viewer);
        $this->savedPosts->annotateIsSaved($posts, $viewer);

        return RepostResource::collection($reposts);
    }
}
