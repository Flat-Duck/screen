<?php

namespace App\Models;

use App\Enums\HashtagModerationState;
use Database\Factories\HashtagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $name Normalized lowercase, no leading '#'.
 * @property HashtagModerationState $moderation_state
 * @property string|null $moderation_reason
 * @property bool|null $is_followed Set per-request by HashtagService for the current viewer — not a DB column.
 */
class Hashtag extends Model
{
    /** @use HasFactory<HashtagFactory> */
    use HasFactory, Searchable;

    /** @return array{name: string} */
    #[SearchUsingPrefix(['name'])]
    public function toSearchableArray(): array
    {
        return ['name' => $this->name];
    }

    /**
     * Keeps moderated tags out of the search index entirely on an indexed driver. The
     * default `database` driver has no index and never consults this, which is why
     * SearchService::hashtags() filters in the query too — both are needed, for different
     * drivers.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->moderation_state->isDiscoverable();
    }

    protected $fillable = [
        'name',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'moderation_state' => HashtagModerationState::Clear->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'moderation_state' => HashtagModerationState::class,
            'moderated_at' => 'datetime',
        ];
    }

    /**
     * A tag only reaches discovery surfaces (trending, explore, search) while Clear.
     * Moderation state is never mass-assignable — it changes through
     * HashtagModerationService, which requires a reason and writes an audit record.
     *
     * @param  Builder<Hashtag>  $query
     * @return Builder<Hashtag>
     */
    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query->where('moderation_state', HashtagModerationState::Clear->value);
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class)->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'hashtag_user')->withTimestamps();
    }

    /** Strips a leading '#' and lowercases — the canonical form stored in `name`. */
    public static function normalize(string $raw): string
    {
        return Str::lower(Str::startsWith($raw, '#') ? Str::substr($raw, 1) : $raw);
    }

    /** Route-bound by name (e.g. `/hashtags/bug`), not id — matches how tags are referenced everywhere else. */
    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /**
     * Normalizes the route segment the same way captions/mentions are normalized on write,
     * so `/hashtags/BUG` or `/hashtags/%23bug` still resolves to the `bug` row.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? $this->getRouteKeyName(), self::normalize((string) $value))->first();
    }
}
