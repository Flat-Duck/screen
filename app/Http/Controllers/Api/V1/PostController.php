<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    public function store(StorePostRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $post = $this->posts->createPost($user, $request->validated());
        $post->is_liked = false;
        $post->loadCount(['likes', 'comments'])->load('user');

        return (new PostResource($post))->response()->setStatusCode(201);
    }

    public function show(Request $request, Post $post): PostResource
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $post->load(['user', 'media'])->loadCount(['likes', 'comments']);
        $post->is_liked = $post->likes()->where('user_id', $viewer->id)->exists();

        return new PostResource($post);
    }

    public function destroy(Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        $this->posts->deletePost($post);

        return response()->json(null, 204);
    }
}
