<?php

namespace App\Services;

use App\Enums\FollowRequestStatus;
use App\Models\FollowRequest;
use App\Models\User;
use App\Notifications\FollowRequestAcceptedNotification;
use App\Notifications\FollowRequestReceivedNotification;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowRequestService
{
    public function __construct(private readonly FollowService $follows) {}

    public function request(User $requester, User $target): FollowRequest
    {
        if ($requester->is($target)) {
            throw ValidationException::withMessages(['user' => 'You cannot follow yourself.']);
        }

        if ($requester->following()->where('followee_id', $target->id)->exists()) {
            throw ValidationException::withMessages(['user' => 'You already follow this user.']);
        }

        $followRequest = FollowRequest::query()->updateOrCreate(
            ['requester_id' => $requester->id, 'target_id' => $target->id],
            ['status' => FollowRequestStatus::Pending, 'responded_at' => null],
        );

        if ($followRequest->wasRecentlyCreated || $followRequest->wasChanged('status')) {
            $target->notify(new FollowRequestReceivedNotification($requester));
        }

        return $followRequest;
    }

    public function cancel(User $requester, User $target): void
    {
        FollowRequest::query()
            ->where('requester_id', $requester->id)
            ->where('target_id', $target->id)
            ->where('status', FollowRequestStatus::Pending)
            ->update(['status' => FollowRequestStatus::Cancelled, 'responded_at' => now()]);
    }

    public function accept(User $target, FollowRequest $followRequest): void
    {
        $this->assertOwnedPendingRequest($target, $followRequest);

        DB::transaction(function () use ($target, $followRequest): void {
            ($this->follows)->follow($followRequest->requester, $target);
            $followRequest->update(['status' => FollowRequestStatus::Accepted, 'responded_at' => now()]);
        });

        $followRequest->requester->notify(new FollowRequestAcceptedNotification($target));
    }

    public function decline(User $target, FollowRequest $followRequest): void
    {
        $this->assertOwnedPendingRequest($target, $followRequest);
        $followRequest->update(['status' => FollowRequestStatus::Declined, 'responded_at' => now()]);
    }

    /** @return CursorPaginator<int, FollowRequest> */
    public function incoming(User $user, int $perPage = 20): CursorPaginator
    {
        return FollowRequest::query()
            ->where('target_id', $user->id)
            ->where('status', FollowRequestStatus::Pending)
            ->with('requester')
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    /** @return CursorPaginator<int, FollowRequest> */
    public function outgoing(User $user, int $perPage = 20): CursorPaginator
    {
        return FollowRequest::query()
            ->where('requester_id', $user->id)
            ->where('status', FollowRequestStatus::Pending)
            ->with('target')
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    private function assertOwnedPendingRequest(User $target, FollowRequest $followRequest): void
    {
        abort_unless($followRequest->target_id === $target->id, 404);
        abort_unless($followRequest->status === FollowRequestStatus::Pending, 409);
    }
}
