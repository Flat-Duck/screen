<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * No factory — tests create reports via the API endpoint.
 *
 * @property int|null $moderation_case_id
 */
class Report extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    /**
     * Client-facing aliases mapped to their model class — the client never sends a raw FQCN.
     *
     * @var array<string, class-string<Model>>
     */
    public const REPORTABLE_TYPES = [
        'post' => Post::class,
        'comment' => Comment::class,
        'user' => User::class,
        'conversation' => Conversation::class,
    ];

    protected $fillable = [
        'reporter_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolution_note',
        'moderation_case_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<ModerationCase, $this> */
    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class);
    }
}
