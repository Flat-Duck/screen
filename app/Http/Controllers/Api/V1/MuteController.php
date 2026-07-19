<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Services\MuteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MuteController extends Controller
{
    public function __construct(private readonly MuteService $mutes) {}

    public function store(Request $request, User $user): JsonResponse
    {
        /** @var User $muter */
        $muter = $request->user();

        $this->mutes->mute($muter, $user);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $muter */
        $muter = $request->user();

        $this->mutes->unmute($muter, $user);

        return response()->json(null, 204);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return UserSummaryResource::collection($this->mutes->mutedUsers($user));
    }
}
