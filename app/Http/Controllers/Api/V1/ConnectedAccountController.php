<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ConnectedAccountResource;
use App\Models\User;
use App\Services\ConnectedAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConnectedAccountController extends Controller
{
    public function __construct(private readonly ConnectedAccountService $connectedAccounts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ConnectedAccountResource::collection($this->connectedAccounts->listFor($user));
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->connectedAccounts->unlink($user, $provider);

        return response()->json(null, 204);
    }
}
