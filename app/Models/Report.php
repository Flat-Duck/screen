<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** No factory — tests create reports via the API endpoint. */
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
    ];

    protected $fillable = [
        'reporter_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'details',
        'status',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
