<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountVisibility;
use App\Http\Resources\FollowRequestResource;
use App\Models\FollowRequest;
use App\Models\User;
use App\Services\BlockService;
use App\Services\FollowRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FollowRequestController extends Controller
{
    public function __construct(
        private readonly FollowRequestService $requests,
        private readonly BlockService $blocks,
    ) {}

    public function store(Request $request, User $user): JsonResponse
    {
        /** @var User $requester */
        $requester = $request->user();
        abort_unless($user->isPubliclyVisible(), 404);
        abort_unless($user->account_visibility === AccountVisibility::Private, 422);
        abort_if($this->blocks->isBlockedEitherWay($requester, $user), 403);

        $followRequest = $this->requests->request($requester, $user)->load(['requester', 'target']);

        return (new FollowRequestResource($followRequest))->response()->setStatusCode(202);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $requester */
        $requester = $request->user();
        $this->requests->cancel($requester, $user);

        return response()->json(null, 204);
    }

    public function incoming(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return FollowRequestResource::collection($this->requests->incoming($user));
    }

    public function outgoing(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return FollowRequestResource::collection($this->requests->outgoing($user));
    }

    public function accept(Request $request, FollowRequest $followRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->requests->accept($user, $followRequest);

        return response()->json(null, 204);
    }

    public function decline(Request $request, FollowRequest $followRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->requests->decline($user, $followRequest);

        return response()->json(null, 204);
    }
}
