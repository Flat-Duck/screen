<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;

class MessageService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MuteService $mutes,
        private readonly ContentFilterService $filters,
    ) {}

    public function send(User $sender, Conversation $conversation, string $body): Message
    {
        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        $recipient = $this->conversations->otherParticipant($conversation, $sender);

        if ($recipient !== null) {
            $conversation->participants()->updateExistingPivot($recipient->id, ['hidden_at' => null]);

            $filtered = $this->filters->apply($message, $sender, $recipient, 'message');

            if (! $filtered && $this->mutes->shouldNotify($recipient, $sender)) {
                $recipient->notify(new NewMessageNotification($conversation, $message, $sender));
            }
        }

        return $message;
    }

    /**
     * The default view — most recent first, cursor-paginated, same convention as every
     * other list endpoint (loading a conversation's history).
     *
     * @return CursorPaginator<int, Message>
     */
    public function messagesFor(Conversation $conversation, User $viewer, int $perPage = 30): CursorPaginator
    {
        return $conversation->messages()
            ->with('sender')
            ->withExists(['filterMatches as is_filtered' => fn ($query) => $query->where('user_id', $viewer->id)])
            ->latest('id')
            ->cursorPaginate($perPage);
    }

    /**
     * The polling view — everything strictly after a given message id, oldest first, capped
     * rather than paginated (a client polling an open thread wants "what's new", not a page).
     *
     * @return Collection<int, Message>
     */
    public function messagesSince(Conversation $conversation, User $viewer, int $afterId, int $limit = 100): Collection
    {
        return $conversation->messages()
            ->with('sender')
            ->withExists(['filterMatches as is_filtered' => fn ($query) => $query->where('user_id', $viewer->id)])
            ->where('id', '>', $afterId)
            ->oldest('id')
            ->limit($limit)
            ->get();
    }
}
