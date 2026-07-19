<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * No factory — tests create conversations via ConversationService/the API endpoint, since
 * a conversation only makes sense with its 2 participants attached atomically.
 *
 * @property Carbon|null $last_message_at
 * @property bool|null $unread Set per-request by ConversationService for the current viewer — not a DB column.
 */
class Conversation extends Model
{
    protected $fillable = [
        'last_message_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, Pivot, 'pivot'>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
