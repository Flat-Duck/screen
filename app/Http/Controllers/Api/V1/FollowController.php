<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Services\BlockService;
use App\Services\FollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FollowController extends Controller
{
    public function __construct(
        private readonly FollowService $follows,
        private readonly BlockService $blocks,
    ) {}

    public function store(Request $request, User $user): JsonResponse
    {
        /** @var User $follower */
        $follower = $request->user();

        if ($this->blocks->isBlockedEitherWay($follower, $user)) {
            abort(403);
        }

        $this->follows->follow($follower, $user);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $follower */
        $follower = $request->user();

        $this->follows->unfollow($follower, $user);

        return response()->json(null, 204);
    }

    public function followers(User $user): AnonymousResourceCollection
    {
        return UserSummaryResource::collection($this->follows->followers($user));
    }

    public function following(User $user): AnonymousResourceCollection
    {
        return UserSummaryResource::collection($this->follows->following($user));
    }
}
