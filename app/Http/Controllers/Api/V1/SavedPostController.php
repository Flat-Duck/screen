<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use App\Services\LikeService;
use App\Services\SavedPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SavedPostController extends Controller
{
    public function __construct(
        private readonly SavedPostService $savedPosts,
        private readonly LikeService $likes,
    ) {}

    public function store(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($post->isVisibleTo($user), 404);

        $this->savedPosts->save($user, $post);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($post->isVisibleTo($user), 404);

        $this->savedPosts->unsave($user, $post);

        return response()->json(null, 204);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $posts = $this->savedPosts->savedPostsFor($user);
        $this->likes->annotateIsLiked($posts->getCollection(), $user);
        // Every post in this exact result set is, by definition, saved by $user — skip
        // the extra query annotateIsSaved() would otherwise run.
        $posts->getCollection()->each(function (Post $post): void {
            $post->is_saved = true;
        });

        return PostResource::collection($posts);
    }
}
