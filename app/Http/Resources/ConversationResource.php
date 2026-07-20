<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Callers must eager-load `participants` filtered to "not the viewer" and set `unread`
 * (see ConversationService::conversationsFor) before resourcing.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'other_participant' => new UserSummaryResource($this->participants->first()),
            'state' => $this->state->value,
            'requested_by' => $this->requested_by,
            'accepted_at' => $this->accepted_at,
            'latest_message' => new MessageResource($this->whenLoaded('messages', fn () => $this->messages->first())),
            'last_message_at' => $this->last_message_at,
            'unread' => (bool) ($this->unread ?? false),
        ];
    }
}
