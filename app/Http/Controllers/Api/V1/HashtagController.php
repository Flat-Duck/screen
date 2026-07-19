<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\HashtagResource;
use App\Http\Resources\PostResource;
use App\Models\Hashtag;
use App\Models\User;
use App\Services\HashtagService;
use App\Services\LikeService;
use App\Services\SavedPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HashtagController extends Controller
{
    public function __construct(
        private readonly HashtagService $hashtags,
        private readonly LikeService $likes,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function show(Request $request, Hashtag $hashtag): HashtagResource
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $hashtag->loadCount('posts');
        $hashtag->is_followed = $this->hashtags->isFollowing($viewer, $hashtag);

        return new HashtagResource($hashtag);
    }

    public function posts(Request $request, Hashtag $hashtag): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $posts = $this->hashtags->postsFor($hashtag, $viewer);
        $this->likes->annotateIsLiked($posts->getCollection(), $viewer);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $viewer);

        return PostResource::collection($posts);
    }

    public function follow(Request $request, Hashtag $hashtag): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->hashtags->follow($user, $hashtag);

        return response()->json(null, 204);
    }

    public function unfollow(Request $request, Hashtag $hashtag): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->hashtags->unfollow($user, $hashtag);

        return response()->json(null, 204);
    }

    public function followed(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $hashtags = $this->hashtags->followedHashtagsFor($user);
        // Every hashtag in this exact result set is, by definition, followed by $user —
        // skip the extra query annotateIsFollowed() would otherwise run.
        $hashtags->getCollection()->each(function (Hashtag $hashtag): void {
            $hashtag->is_followed = true;
        });

        return HashtagResource::collection($hashtags);
    }
}
