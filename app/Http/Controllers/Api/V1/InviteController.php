<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserInviteResource;
use App\Models\User;
use App\Services\InviteCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InviteController extends Controller
{
    public function __construct(private readonly InviteCodeService $invites) {}

    /** `GET /v1/me/invites` — the caller's own invite redemptions. Own invite_code/points_balance
     * ride on the user's own UserResource (see login/register/GET users/{user} responses) rather
     * than duplicated here, since those are already fetched wherever the caller's profile is. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return UserInviteResource::collection($this->invites->myInvites($user));
    }

    /** Mints an opaque invitation URL for the authenticated caller. */
    public function shareLink(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'url' => $this->invites->createShareLink($user),
        ]], 201);
    }
}
