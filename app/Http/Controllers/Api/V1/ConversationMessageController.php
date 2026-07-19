<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\BlockService;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationMessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly ConversationService $conversations,
        private readonly BlockService $blocks,
    ) {}

    /**
     * `?after=<message id>` switches to the lightweight polling view (everything newer,
     * oldest first, capped not paginated) for a client with an open thread — omitted, this
     * returns the normal cursor-paginated history view like every other list endpoint.
     */
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);

        $after = $request->integer('after');

        if ($after > 0) {
            return MessageResource::collection($this->messages->messagesSince($conversation, $after));
        }

        return MessageResource::collection($this->messages->messagesFor($conversation));
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();

        $other = $this->conversations->otherParticipant($conversation, $user);

        if ($other !== null && $this->blocks->isBlockedEitherWay($user, $other)) {
            abort(403);
        }

        $message = $this->messages->send($user, $conversation, $request->validated()['body']);
        $message->load('sender');

        return (new MessageResource($message))->response()->setStatusCode(201);
    }
}
