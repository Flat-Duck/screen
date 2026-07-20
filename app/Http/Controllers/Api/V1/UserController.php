<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FollowRequestStatus;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\FollowRequest;
use App\Models\User;
use App\Services\BlockService;
use App\Services\LikeService;
use App\Services\PostQueryService;
use App\Services\ProfileService;
use App\Services\SavedPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly PostQueryService $posts,
        private readonly LikeService $likes,
        private readonly BlockService $blocks,
        private readonly SavedPostService $savedPosts,
    ) {}

    public function show(Request $request, User $user): UserResource
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($user->isPubliclyVisible(), 404);

        // Hides existence rather than a 403 — doesn't reveal to either party which of
        // them initiated the block.
        if ($this->blocks->isBlockedEitherWay($viewer, $user)) {
            abort(404);
        }

        $profile = $this->profiles->getPublicProfile($user);
        $profile->is_following = $viewer->following()->where('followee_id', $user->id)->exists();
        $profile->follows_you = $user->following()->where('followee_id', $viewer->id)->exists();
        $hasPendingFollowRequest = FollowRequest::query()
            ->where('requester_id', $viewer->id)
            ->where('target_id', $user->id)
            ->where('status', FollowRequestStatus::Pending)
            ->exists();
        $profile->follow_request_status = $hasPendingFollowRequest ? FollowRequestStatus::Pending->value : null;
        $profile->is_blocked = $viewer->blockedUsers()->where('blocked_id', $user->id)->exists();
        $profile->is_blocked_by = $viewer->blockedBy()->where('blocker_id', $user->id)->exists();

        return new UserResource($profile);
    }

    public function posts(Request $request, User $user): AnonymousResourceCollection
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($user->isPubliclyVisible(), 404);

        if ($this->blocks->isBlockedEitherWay($viewer, $user)) {
            abort(404);
        }

        abort_unless($user->account_visibility->value === 'public'
            || $viewer->is($user)
            || $viewer->following()->where('followee_id', $user->id)->exists(), 404);

        $posts = $this->posts->postsForUser($user, $viewer);
        $this->likes->annotateIsLiked($posts->getCollection(), $viewer);
        $this->savedPosts->annotateIsSaved($posts->getCollection(), $viewer);

        return PostResource::collection($posts);
    }

    public function topTags(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isPubliclyVisible(), 404);

        /** @var User $viewer */
        $viewer = $request->user();
        abort_unless($user->account_visibility->value === 'public'
            || $viewer->is($user)
            || $viewer->following()->where('followee_id', $user->id)->exists(), 404);

        return response()->json(['data' => $this->posts->topHashtagsFor($user)]);
    }
}
