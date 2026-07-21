<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PermanentlyDeletePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use App\Services\LikeService;
use App\Services\PostLibraryService;
use App\Services\SavedPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class PostLibraryController extends Controller
{
    public function __construct(
        private readonly PostLibraryService $library,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function archive(Request $request, int $postId): JsonResponse
    {
        $this->library->archive($this->user($request), $postId);

        return response()->json(null, 204);
    }

    public function unarchive(Request $request, int $postId): JsonResponse
    {
        $this->library->unarchive($this->user($request), $postId);

        return response()->json(null, 204);
    }

    public function archived(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $posts = $this->library->archived($user);
        $this->annotate($posts->getCollection(), $user);

        return PostResource::collection($posts);
    }

    public function recentlyDeleted(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $posts = $this->library->recentlyDeleted($user);
        $this->annotate($posts->getCollection(), $user);

        return PostResource::collection($posts);
    }

    public function restore(Request $request, int $postId): PostResource
    {
        $user = $this->user($request);
        $post = $this->library->restore($user, $postId);
        $post->load(['user', 'media', 'category'])->loadCount(['likes', 'comments']);
        $this->annotate(collect([$post]), $user);

        return new PostResource($post);
    }

    public function permanentlyDelete(PermanentlyDeletePostRequest $request, int $postId): JsonResponse
    {
        $this->library->permanentlyDelete($this->user($request), $postId);

        return response()->json(null, 204);
    }

    /** @param Collection<int, Post> $posts */
    private function annotate(Collection $posts, User $user): void
    {
        $this->likes->annotateIsLiked($posts, $user);
        $this->savedPosts->annotateIsSaved($posts, $user);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
