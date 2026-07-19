<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** No factory — tests create mentions via post/comment creation, which auto-syncs them. */
class Mention extends Model
{
    protected $fillable = [
        'mentioner_id',
        'mentioned_user_id',
        'mentionable_type',
        'mentionable_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function mentioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function mentionable(): MorphTo
    {
        return $this->morphTo();
    }
}
