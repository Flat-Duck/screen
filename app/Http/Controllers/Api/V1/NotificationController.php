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

        $user->unreadNotifications->markAsRead();

        return response()->json(null, 204);
    }
}
