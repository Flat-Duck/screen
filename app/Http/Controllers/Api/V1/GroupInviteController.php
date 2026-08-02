<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\GroupInviteResource;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use App\Services\GroupInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupInviteController extends Controller
{
    public function __construct(private readonly GroupInviteService $invites) {}

    public function store(Request $request, Group $group): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $invite = $this->invites
            ->invite($this->user($request), $group, User::query()->findOrFail((int) $data['user_id']))
            ->load(['group', 'inviter']);

        return (new GroupInviteResource($invite))->response()->setStatusCode(202);
    }

    public function incoming(Request $request): AnonymousResourceCollection
    {
        return GroupInviteResource::collection($this->invites->incoming($this->user($request)));
    }

    public function accept(Request $request, GroupInvite $groupInvite): JsonResponse
    {
        $this->invites->accept($this->user($request), $groupInvite);

        return response()->json(null, 204);
    }

    public function decline(Request $request, GroupInvite $groupInvite): JsonResponse
    {
        $this->invites->decline($this->user($request), $groupInvite);

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
