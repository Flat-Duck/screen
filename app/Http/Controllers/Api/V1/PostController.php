<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Posts\CreatePost;
use App\Actions\Posts\DeletePost;
use App\Actions\Posts\UpdatePost;
use App\Enums\UserRestrictionType;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\Scopes\NotArchivedScope;
use App\Models\User;
use App\Services\BlockService;
use App\Services\SavedPostService;
use App\Services\UserRestrictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function __construct(
        private readonly BlockService $blocks,
        private readonly SavedPostService $savedPosts,
        private readonly UserRestrictionService $restrictions,
    ) {}

    public function store(StorePostRequest $request, CreatePost $createPost): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->restrictions->enforce($user, UserRestrictionType::Posting);

        $post = $createPost($user, $request->toData());
        $post->is_liked = false;
        $post->is_saved = false;
        $post->loadCount(['likes', 'comments'])->load(['user', 'category']);

        return (new PostResource($post))->response()->setStatusCode(201);
    }

    public function show(Request $request, Post $post): PostResource
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $post->load(['user', 'media', 'category'])->loadCount(['likes', 'comments']);

        if ($this->blocks->isBlockedEitherWay($viewer, $post->user)) {
            abort(404);
        }

        abort_unless($post->isVisibleTo($viewer), 404);

        $post->is_liked = $post->likes()->where('user_id', $viewer->id)->exists();
        $post->is_saved = $this->savedPosts->isSaved($viewer, $post);

        return new PostResource($post);
    }

    public function update(UpdatePostRequest $request, Post $post, UpdatePost $updatePost): PostResource
    {
        $this->authorize('update', $post);

        /** @var User $viewer */
        $viewer = $request->user();

        $post = $updatePost($post, $request->validated());
        $post->load(['user', 'media', 'category'])->loadCount(['likes', 'comments']);
        $post->is_liked = $post->likes()->where('user_id', $viewer->id)->exists();
        $post->is_saved = $this->savedPosts->isSaved($viewer, $post);

        return new PostResource($post);
    }

    public function destroy(int $post, DeletePost $deletePost): JsonResponse
    {
        $post = Post::withoutGlobalScope(NotArchivedScope::class)->findOrFail($post);
        $this->authorize('delete', $post);

        $deletePost($post);

        return response()->json(null, 204);
    }
}
