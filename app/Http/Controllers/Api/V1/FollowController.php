<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountVisibility;
use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Services\BlockService;
use App\Services\FollowRequestService;
use App\Services\FollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FollowController extends Controller
{
    public function __construct(
        private readonly FollowService $follows,
        private readonly BlockService $blocks,
        private readonly FollowRequestService $followRequests,
    ) {}

    public function store(Request $request, User $user): JsonResponse
    {
        /** @var User $follower */
        $follower = $request->user();

        abort_unless($user->isPubliclyVisible(), 404);

        if ($this->blocks->isBlockedEitherWay($follower, $user)) {
            abort(403);
        }

        if ($user->account_visibility === AccountVisibility::Private) {
            $followRequest = $this->followRequests->request($follower, $user);

            return response()->json(['data' => ['status' => 'requested', 'request_id' => $followRequest->id]], 202);
        }

        $this->follows->follow($follower, $user);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $follower */
        $follower = $request->user();

        $this->follows->unfollow($follower, $user);
        $this->followRequests->cancel($follower, $user);

        return response()->json(null, 204);
    }

    public function followers(Request $request, User $user): AnonymousResourceCollection
    {
        abort_unless($user->isPubliclyVisible(), 404);
        /** @var User $viewer */
        $viewer = $request->user();
        $this->authorizePrivateRelationshipList($viewer, $user);

        return UserSummaryResource::collection($this->follows->followers($user));
    }

    public function following(Request $request, User $user): AnonymousResourceCollection
    {
        abort_unless($user->isPubliclyVisible(), 404);
        /** @var User $viewer */
        $viewer = $request->user();
        $this->authorizePrivateRelationshipList($viewer, $user);

        return UserSummaryResource::collection($this->follows->following($user));
    }

    private function authorizePrivateRelationshipList(User $viewer, User $profile): void
    {
        if ($profile->account_visibility !== AccountVisibility::Private || $viewer->is($profile)) {
            return;
        }

        abort_unless($viewer->following()->where('followee_id', $profile->id)->exists(), 404);
    }
}
