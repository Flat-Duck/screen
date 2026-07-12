<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LikeService;
use App\Services\PostService;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly PostService $posts,
        private readonly LikeService $likes,
    ) {}

    public function show(Request $request, User $user): UserResource
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $profile = $this->profiles->getPublicProfile($user);
        $profile->is_following = $viewer->following()->where('followee_id', $user->id)->exists();

        return new UserResource($profile);
    }

    public function posts(Request $request, User $user): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $posts = $this->posts->postsForUser($user);
        $this->likes->annotateIsLiked($posts->getCollection(), $viewer);

        return PostResource::collection($posts);
    }

    public function topTags(User $user): JsonResponse
    {
        return response()->json(['data' => $this->posts->topHashtagsFor($user)]);
    }
}
