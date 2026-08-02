<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\FollowRequestResource;
use App\Models\FollowRequest;
use App\Models\User;
use App\Services\FollowRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Incoming/outgoing/accept/decline only — there is deliberately no store()/destroy() here.
 * FollowController::store()/destroy() (POST|DELETE /v1/users/{user}/follow) already create/cancel
 * the pending FollowRequest internally for private accounts via this same FollowRequestService, so
 * a dedicated POST|DELETE /v1/users/{user}/follow-requests would just be a second, redundant entry
 * point to the identical action.
 */
class FollowRequestController extends Controller
{
    public function __construct(
        private readonly FollowRequestService $requests,
    ) {}

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
