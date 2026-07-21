<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\IngestContentEvents;
use App\Http\Requests\StoreContentEventsRequest;
use App\Models\DeviceSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ContentAnalyticsController extends Controller
{
    public function store(StoreContentEventsRequest $request, IngestContentEvents $ingest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $session = DeviceSession::query()
            ->where('personal_access_token_id', $token->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->whereNull('revoked_at')
            ->first();
        abort_if($session === null, 401, 'An active device session is required.');

        /** @var list<array<string, mixed>> $events */
        $events = array_values($request->validated('events'));

        return response()->json(['accepted_event_ids' => $ingest($user, $session, $events)]);
    }
}
