<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function store(StoreConversationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $other = User::query()->findOrFail((int) $request->validated()['user_id']);

        $conversation = $this->conversations->startConversation($user, $other);
        $conversation->load(['participants' => fn ($query) => $query->where('users.id', '!=', $user->id)]);
        $conversation->unread = false;

        return (new ConversationResource($conversation))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ConversationResource::collection($this->conversations->conversationsFor($user));
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();

        $this->conversations->markRead($user, $conversation);

        return response()->json(null, 204);
    }
}
