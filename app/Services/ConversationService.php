<?php

namespace App\Services;

use App\Enums\ConversationState;
use App\Enums\InteractionAudience;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageRequestReceivedNotification;
use App\Notifications\NewMessageNotification;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 1:1 only — every method here assumes (and the only creation path, startConversation(),
 * enforces) exactly 2 participants per conversation. The `conversation_participants` schema
 * itself doesn't enforce that limit (deliberately, for a future group-chat feature), so
 * this invariant lives here in the service, not the database.
 */
class ConversationService
{
    public function __construct(
        private readonly BlockService $blocks,
        private readonly InteractionPermissionService $interactions,
        private readonly SettingsService $settings,
        private readonly MuteService $mutes,
        private readonly ContentFilterService $filters,
    ) {}

    /**
     * Idempotent find-or-create, mirroring ModerationService::report()'s firstOrCreate
     * pattern — starting a conversation with someone you already have one with just
     * returns the existing thread.
     */
    public function startConversation(User $user, User $other, ?string $initialMessage = null): Conversation
    {
        if ($user->is($other)) {
            throw ValidationException::withMessages([
                'user_id' => 'You cannot message yourself.',
            ]);
        }

        if ($this->blocks->isBlockedEitherWay($user, $other)) {
            throw ValidationException::withMessages([
                'user_id' => 'You cannot message this user.',
            ]);
        }

        $existing = $this->findConversationBetween($user, $other);

        if ($existing !== null) {
            return $this->reuseExisting($existing, $user, $other, $initialMessage);
        }

        $isActive = $this->interactions->canMessage($user, $other);
        $this->assertMayRequest($other, $isActive, $initialMessage);

        $conversation = DB::transaction(function () use ($user, $other, $initialMessage, $isActive): Conversation {
            $conversation = Conversation::create([
                'state' => $isActive ? ConversationState::Active : ConversationState::Requested,
                'requested_by' => $isActive ? null : $user->id,
            ]);
            $conversation->participants()->attach([$user->id, $other->id]);

            if ($initialMessage !== null) {
                $message = $conversation->messages()->create(['sender_id' => $user->id, 'body' => $initialMessage]);
                $conversation->update(['last_message_at' => $message->created_at]);
            }

            return $conversation;
        });

        $initial = $initialMessage !== null ? $conversation->messages()->latest('id')->first() : null;
        $initialWasFiltered = $initial instanceof Message
            && $this->filters->apply($initial, $user, $other, 'message');

        if (! $isActive) {
            $other->notify(new MessageRequestReceivedNotification($conversation, $user));
        } elseif ($initial instanceof Message && ! $initialWasFiltered) {
            $this->notifyInitialMessage($conversation, $initial, $user, $other);
        }

        return $conversation;
    }

    /** @return CursorPaginator<int, Conversation> */
    public function requestsFor(User $user, int $perPage = 20): CursorPaginator
    {
        return Conversation::query()
            ->where('state', ConversationState::Requested)
            ->where('requested_by', '!=', $user->id)
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id)->whereNull('conversation_participants.hidden_at'))
            ->with([
                'participants' => fn ($query) => $query->where('users.id', '!=', $user->id),
                'messages' => fn ($query) => $query->with('sender')
                    ->withExists(['filterMatches as is_filtered' => fn ($matches) => $matches->where('user_id', $user->id)])
                    ->latest('id')->limit(1),
            ])
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    public function accept(User $user, Conversation $conversation): void
    {
        DB::transaction(function () use ($user, $conversation): void {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            abort_unless($locked->state === ConversationState::Requested && $locked->requested_by !== $user->id && $this->isParticipant($user, $locked), 404);
            $locked->update(['state' => ConversationState::Active, 'accepted_at' => now(), 'rejected_at' => null]);
        });
    }

    public function reject(User $user, Conversation $conversation): void
    {
        DB::transaction(function () use ($user, $conversation): void {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            abort_unless($locked->state === ConversationState::Requested && $locked->requested_by !== $user->id && $this->isParticipant($user, $locked), 404);
            $locked->update(['state' => ConversationState::Rejected, 'rejected_at' => now()]);
        });
    }

    public function hide(User $user, Conversation $conversation): void
    {
        abort_unless($this->isParticipant($user, $conversation), 404);
        $conversation->participants()->updateExistingPivot($user->id, ['hidden_at' => now()]);
    }

    private function findConversationBetween(User $user, User $other): ?Conversation
    {
        // Every conversation has exactly 2 participants (the invariant this service
        // maintains), so "has a participant matching $user AND has a participant matching
        // $other" is sufficient to identify the 1:1 thread between them, no extra count
        // check needed.
        return Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $other->id))
            ->first();
    }

    public function isParticipant(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('users.id', $user->id)->exists();
    }

    public function otherParticipant(Conversation $conversation, User $viewer): ?User
    {
        return $conversation->participants->firstWhere('id', '!=', $viewer->id);
    }

    public function markRead(User $user, Conversation $conversation): void
    {
        $conversation->participants()->updateExistingPivot($user->id, ['last_read_at' => now()]);
    }

    /**
     * @return CursorPaginator<int, Conversation>
     */
    public function conversationsFor(User $user, int $perPage = 20): CursorPaginator
    {
        $paginator = Conversation::query()
            ->where('state', ConversationState::Active)
            ->whereIn('id', DB::table('conversation_participants')->where('user_id', $user->id)->select('conversation_id'))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id)->whereNull('conversation_participants.hidden_at'))
            ->with(['participants' => fn ($query) => $query->where('users.id', '!=', $user->id)])
            ->orderByDesc('last_message_at')
            ->cursorPaginate($perPage);

        $conversationIds = $paginator->getCollection()->pluck('id');

        $lastReadAts = DB::table('conversation_participants')
            ->where('user_id', $user->id)
            ->whereIn('conversation_id', $conversationIds)
            ->pluck('last_read_at', 'conversation_id');

        $paginator->getCollection()->each(function (Conversation $conversation) use ($lastReadAts): void {
            $lastReadAtValue = $lastReadAts[$conversation->id] ?? null;
            $lastReadAt = $lastReadAtValue ? Carbon::parse($lastReadAtValue) : null;

            $conversation->unread = $conversation->last_message_at !== null
                && ($lastReadAt === null || $conversation->last_message_at->gt($lastReadAt));
        });

        return $paginator;
    }

    private function reuseExisting(Conversation $conversation, User $user, User $other, ?string $initialMessage): Conversation
    {
        if ($conversation->state === ConversationState::Active || $conversation->state === ConversationState::Requested) {
            $conversation->participants()->updateExistingPivot($user->id, ['hidden_at' => null]);

            return $conversation;
        }

        $cooldownDays = (int) config('social.message_request_rejection_cooldown_days', 30);
        if ($conversation->rejected_at?->addDays($cooldownDays)->isFuture()) {
            throw ValidationException::withMessages(['user_id' => 'You cannot send another message request yet.']);
        }

        $isActive = $this->interactions->canMessage($user, $other);
        $this->assertMayRequest($other, $isActive, $initialMessage);
        $conversation->update([
            'state' => $isActive ? ConversationState::Active : ConversationState::Requested,
            'requested_by' => $isActive ? null : $user->id,
            'accepted_at' => null,
            'rejected_at' => null,
        ]);
        $conversation->participants()->updateExistingPivot($user->id, ['hidden_at' => null]);
        $conversation->participants()->updateExistingPivot($other->id, ['hidden_at' => null]);

        if ($initialMessage !== null) {
            $message = $conversation->messages()->create(['sender_id' => $user->id, 'body' => $initialMessage]);
            $conversation->update(['last_message_at' => $message->created_at]);
        }

        $initial = $initialMessage !== null ? $conversation->messages()->latest('id')->first() : null;
        $initialWasFiltered = $initial instanceof Message
            && $this->filters->apply($initial, $user, $other, 'message');

        if (! $isActive) {
            $other->notify(new MessageRequestReceivedNotification($conversation, $user));
        } elseif ($initial instanceof Message && ! $initialWasFiltered) {
            $this->notifyInitialMessage($conversation, $initial, $user, $other);
        }

        return $conversation;
    }

    private function assertMayRequest(User $recipient, bool $isActive, ?string $initialMessage): void
    {
        if ($isActive) {
            return;
        }

        $audience = $this->settings->getFor($recipient)['interactions']['messages_from'] ?? InteractionAudience::Everyone->value;
        if ($audience === InteractionAudience::NoOne->value) {
            throw ValidationException::withMessages(['user_id' => 'This user does not accept messages.']);
        }

        if ($initialMessage === null || trim($initialMessage) === '') {
            throw ValidationException::withMessages(['initial_message' => 'An initial message is required for a message request.']);
        }
    }

    private function notifyInitialMessage(Conversation $conversation, Message $message, User $sender, User $recipient): void
    {
        if ($this->mutes->shouldNotify($recipient, $sender)) {
            $recipient->notify(new NewMessageNotification($conversation, $message, $sender));
        }
    }
}
