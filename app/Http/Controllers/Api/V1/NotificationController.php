<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return NotificationResource::collection($user->notifications()->cursorPaginate(20));
    }

    /**
     * Unread count for the bottom-nav badge.
     *
     * Deliberately its own endpoint rather than a `meta.unread_count` on `index`: the badge is
     * shown on every screen that hosts the bottom nav and refreshes whenever one resumes, so
     * making it piggyback on the listing would pull 20 notification rows plus their resources
     * every time. This is a single indexed COUNT and carries its own, looser throttle.
     *
     * The count is exact; the client is what renders anything above 99 as "99+".
     */
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => ['count' => $user->notifications()->whereNull('read_at')->count()],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->findOrFail($notification)->markAsRead();

        return response()->json(null, 204);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // A single bulk UPDATE, not $user->unreadNotifications->markAsRead() — that loads every
        // unread row into memory and issues one UPDATE per row via Eloquent's own
        // DatabaseNotificationCollection::markAsRead(), unbounded by a user-controlled backlog size.
        $user->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(null, 204);
    }
}
