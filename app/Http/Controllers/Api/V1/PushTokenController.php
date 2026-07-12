<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\DestroyPushTokenRequest;
use App\Http\Requests\StorePushTokenRequest;
use App\Models\User;
use App\Services\PushTokenService;
use Illuminate\Http\JsonResponse;

class PushTokenController extends Controller
{
    public function __construct(private readonly PushTokenService $pushTokens) {}

    public function store(StorePushTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->pushTokens->register($user, $request->string('fcm_token')->toString());

        return response()->json(null, 204);
    }

    public function destroy(DestroyPushTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->pushTokens->unregister($user, $request->string('fcm_token')->toString());

        return response()->json(null, 204);
    }
}
