<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\RevokeOtherSessionsRequest;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return SessionResource::collection($this->sessions->listFor($user));
    }

    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->sessions->revoke($user, $tokenId);

        return response()->json(null, 204);
    }

    public function revokeOthers(RevokeOtherSessionsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $currentToken */
        $currentToken = $user->currentAccessToken();

        $this->sessions->revokeOthers($user, $currentToken->id);

        return response()->json(null, 204);
    }
}
