<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * No factory — tests create messages via MessageService/the API endpoint.
 *
 * @property bool|null $is_filtered Set per-request for the current viewer — not a DB column.
 */
class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
    ];

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return MorphMany<ContentFilterMatch, $this> */
    public function filterMatches(): MorphMany
    {
        return $this->morphMany(ContentFilterMatch::class, 'filterable');
    }
}
