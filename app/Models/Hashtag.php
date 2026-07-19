<?php

namespace App\Models;

use Database\Factories\HashtagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name Normalized lowercase, no leading '#'.
 * @property bool|null $is_followed Set per-request by HashtagService for the current viewer — not a DB column.
 */
class Hashtag extends Model
{
    /** @use HasFactory<HashtagFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
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
