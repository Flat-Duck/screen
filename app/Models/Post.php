<?php

namespace App\Models;

use App\Enums\PostPurgeStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A screenshot post: 1..N ordered PostMedia images plus an optional caption.
 * `status` tracks thumbnail-generation progress only — the post is visible and its
 * original images are servable the instant it's created (see PostMedia).
 *
 * @property Carbon|null $account_deleted_at Set only when this post was
 *                                           soft-deleted as part of the whole account being deleted (AccountService), not when the
 *                                           user deleted this specific post on its own — see RestoreDeletedUser.
 * @property bool|null $is_liked Set per-request by LikeService for the current viewer — not a DB column.
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'caption',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_deleted_at' => 'datetime',
            'purge_status' => PostPurgeStatus::class,
            'purge_attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PostMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    /**
     * @return HasMany<Like, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return BelongsToMany<Hashtag, $this>
     */
    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class);
    }
}
