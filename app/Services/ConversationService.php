<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
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
    public function __construct(private readonly BlockService $blocks) {}

    /**
     * Idempotent find-or-create, mirroring ModerationService::report()'s firstOrCreate
     * pattern — starting a conversation with someone you already have one with just
     * returns the existing thread.
     */
    public function startConversation(User $user, User $other): Conversation
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
            return $existing;
        }

        $conversation = Conversation::create();
        $conversation->participants()->attach([$user->id, $other->id]);

        return $conversation;
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
            ->whereIn('id', DB::table('conversation_participants')->where('user_id', $user->id)->select('conversation_id'))
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
}
