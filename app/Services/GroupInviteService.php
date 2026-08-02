<?php

namespace App\Services;

use App\Enums\GroupInviteStatus;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Same "updateOrCreate a pending row, unique per pair, accept/decline flips its status"
 * shape as FollowRequestService — a group invite is just that relationship scoped to
 * (group, invitee) instead of (requester, target).
 */
class GroupInviteService
{
    public function __construct(private readonly GroupService $groups) {}

    public function invite(User $inviter, Group $group, User $invitee): GroupInvite
    {
        abort_unless($group->roleFor($inviter) === 'admin', 403);

        if ($group->isMember($invitee)) {
            throw ValidationException::withMessages(['user' => 'This user is already a member of the group.']);
        }

        return GroupInvite::query()->updateOrCreate(
            ['group_id' => $group->id, 'invitee_id' => $invitee->id],
            ['inviter_id' => $inviter->id, 'status' => GroupInviteStatus::Pending, 'responded_at' => null],
        );
    }

    public function accept(User $user, GroupInvite $invite): void
    {
        $this->assertOwnedPendingInvite($user, $invite);

        DB::transaction(function () use ($user, $invite): void {
            $this->groups->join($user, $invite->group);
            $invite->update(['status' => GroupInviteStatus::Accepted, 'responded_at' => now()]);
        });
    }

    public function decline(User $user, GroupInvite $invite): void
    {
        $this->assertOwnedPendingInvite($user, $invite);
        $invite->update(['status' => GroupInviteStatus::Declined, 'responded_at' => now()]);
    }

    /** @return CursorPaginator<int, GroupInvite> */
    public function incoming(User $user, int $perPage = 20): CursorPaginator
    {
        return GroupInvite::query()
            ->where('invitee_id', $user->id)
            ->where('status', GroupInviteStatus::Pending)
            ->with(['group', 'inviter'])
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    private function assertOwnedPendingInvite(User $user, GroupInvite $invite): void
    {
        abort_unless($invite->invitee_id === $user->id, 404);
        abort_unless($invite->status === GroupInviteStatus::Pending, 409);
    }
}
