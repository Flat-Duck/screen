<?php

namespace App\Models;

use App\Enums\AccountVisibility;
use App\Enums\PostPurgeStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;

/**
 * A screenshot post: 1..N ordered PostMedia images plus an optional caption.
 * `status` tracks thumbnail-generation progress only — the post is visible and its
 * original images are servable the instant it's created (see PostMedia).
 *
 * @property Carbon|null $account_deleted_at Set only when this post was
 *                                           soft-deleted as part of the whole account being deleted (AccountService), not when the
 *                                           user deleted this specific post on its own — see RestoreDeletedUser.
 * @property bool|null $is_liked Set per-request by LikeService for the current viewer — not a DB column.
 * @property bool|null $is_saved Set per-request by SavedPostService for the current viewer — not a DB column.
 * @property bool $comments_enabled
 * @property bool $reposts_enabled
 * @property bool $recommendation_eligible
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, Searchable, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'comments_enabled' => true,
        'reposts_enabled' => true,
        'recommendation_eligible' => true,
    ];

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'caption',
        'searchable_text',
        'status',
        'comments_enabled',
        'reposts_enabled',
        'recommendation_eligible',
        'moderated_at',
        'moderation_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_deleted_at' => 'datetime',
            'purge_status' => PostPurgeStatus::class,
            'purge_attempted_at' => 'datetime',
            'edited_at' => 'datetime',
            'comments_enabled' => 'boolean',
            'reposts_enabled' => 'boolean',
            'recommendation_eligible' => 'boolean',
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Post $post): void {
            if ($post->isDirty('caption') || ! $post->exists) {
                $post->searchable_text = $post->caption ?? '';
            }
        });
    }

    /** @return array{searchable_text: string} */
    #[SearchUsingFullText(['searchable_text'])]
    public function toSearchableArray(): array
    {
        return ['searchable_text' => (string) $this->searchable_text];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->user()->publiclyVisible()->exists();
    }

    /** @param Builder<Post> $query */
    public function scopeFromPubliclyVisibleAuthors(Builder $query): void
    {
        $query->whereIn('user_id', User::query()->publiclyVisible()->select('id'));
    }

    /** @param Builder<Post> $query */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $visibleAuthorIds = User::query()
            ->publiclyVisible()
            ->where(function (Builder $users) use ($viewer): void {
                $users->where('account_visibility', AccountVisibility::Public->value)
                    ->orWhere('id', $viewer->id)
                    ->orWhereIn('id', $viewer->following()->select('users.id'));
            })
            ->select('id');

        $query->whereIn('user_id', $visibleAuthorIds);
    }

    public function isVisibleTo(User $viewer): bool
    {
        $author = $this->relationLoaded('user') ? $this->user : $this->user()->firstOrFail();

        return $author->isPubliclyVisible()
            && ($author->account_visibility === AccountVisibility::Public
                || $viewer->is($author)
                || $viewer->following()->where('followee_id', $author->id)->exists());
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
    /**
     * @return MorphMany<Like, $this>
     */
    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
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

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }
}
