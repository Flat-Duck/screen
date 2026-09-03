<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HashtagModerationState;
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

    public function trending(Request $request): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $hashtags = $this->hashtags->trending((int) ($validated['limit'] ?? 10), (int) ($validated['days'] ?? 7));
        $this->hashtags->annotateIsFollowed($hashtags, $viewer);

        return HashtagResource::collection($hashtags);
    }

    public function show(Request $request, Hashtag $hashtag): HashtagResource
    {
        $this->abortIfBlocked($hashtag);

        /** @var User $viewer */
        $viewer = $request->user();

        $hashtag->loadCount('posts');
        $hashtag->is_followed = $this->hashtags->isFollowing($viewer, $hashtag);

        return new HashtagResource($hashtag);
    }

    public function posts(Request $request, Hashtag $hashtag): AnonymousResourceCollection
    {
        $this->abortIfBlocked($hashtag);

        /** @var User $viewer */
        $viewer = $request->user();

        $posts = $this->hashtags->postsFor($hashtag, $viewer);
        $this->likes->annotateLikes($posts->getCollection(), $viewer);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $viewer);

        return PostResource::collection($posts);
    }

    public function follow(Request $request, Hashtag $hashtag): JsonResponse
    {
        $this->abortIfBlocked($hashtag);

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

    /**
     * A blocked tag stops existing as far as the API is concerned. NotRecommended is
     * deliberately *not* covered here — that state only removes discovery, so the tag page
     * still resolves for anyone who reaches it directly.
     *
     * Unfollow stays reachable in every state so a user who followed a tag before it was
     * blocked is never stuck with an entry they cannot remove.
     */
    private function abortIfBlocked(Hashtag $hashtag): void
    {
        abort_if($hashtag->moderation_state === HashtagModerationState::Blocked, 404);
    }
}
