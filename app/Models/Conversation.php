<?php

namespace App\Models;

use App\Enums\ConversationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * No factory — tests create conversations via ConversationService/the API endpoint, since
 * a conversation only makes sense with its 2 participants attached atomically.
 *
 * @property Carbon|null $last_message_at
 * @property ConversationState $state
 * @property int|null $requested_by
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property bool|null $unread Set per-request by ConversationService for the current viewer — not a DB column.
 */
class Conversation extends Model
{
    protected $fillable = [
        'last_message_at',
        'state',
        'requested_by',
        'accepted_at',
        'rejected_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['state' => ConversationState::Active->value];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'state' => ConversationState::class,
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, Pivot, 'pivot'>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withPivot('hidden_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
