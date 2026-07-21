<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationState;
use App\Enums\UserRestrictionType;
use App\Http\Requests\StoreConversationReportRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\ReportResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\ModerationService;
use App\Services\UserRestrictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ModerationService $moderation,
        private readonly UserRestrictionService $restrictions,
    ) {}

    public function store(StoreConversationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->restrictions->enforce($user, UserRestrictionType::Messaging);

        $other = User::query()->findOrFail((int) $request->validated()['user_id']);

        $conversation = $this->conversations->startConversation($user, $other, $request->validated()['initial_message'] ?? null);
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

    public function requests(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ConversationResource::collection($this->conversations->requestsFor($user));
    }

    public function accept(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->conversations->accept($user, $conversation);

        return response()->json(null, 204);
    }

    public function reject(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->conversations->reject($user, $conversation);

        return response()->json(null, 204);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->conversations->hide($user, $conversation);

        return response()->json(null, 204);
    }

    public function report(StoreConversationReportRequest $request, Conversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->conversations->isParticipant($user, $conversation), 404);
        $data = $request->validated();
        $report = $this->moderation->report($user, 'conversation', $conversation->id, $data['reason'], $data['details'] ?? null);

        return (new ReportResource($report))->response()->setStatusCode(201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        /** @var User $user */
        $user = $request->user();

        abort_unless($conversation->state === ConversationState::Active, 409);

        $this->conversations->markRead($user, $conversation);

        return response()->json(null, 204);
    }
}
