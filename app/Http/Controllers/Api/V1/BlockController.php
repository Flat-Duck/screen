<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Services\BlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlockController extends Controller
{
    public function __construct(private readonly BlockService $blocks) {}

    public function store(Request $request, User $user): JsonResponse
    {
        /** @var User $blocker */
        $blocker = $request->user();

        $this->blocks->block($blocker, $user);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $blocker */
        $blocker = $request->user();

        $this->blocks->unblock($blocker, $user);

        return response()->json(null, 204);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return UserSummaryResource::collection($this->blocks->blockedUsers($user));
    }
}
