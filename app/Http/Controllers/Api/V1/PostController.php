<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Posts\CreatePost;
use App\Actions\Posts\DeletePost;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function store(StorePostRequest $request, CreatePost $createPost): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $post = $createPost($user, $request->toData());
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

    public function destroy(Post $post, DeletePost $deletePost): JsonResponse
    {
        $this->authorize('delete', $post);

        $deletePost($post);

        return response()->json(null, 204);
    }
}
