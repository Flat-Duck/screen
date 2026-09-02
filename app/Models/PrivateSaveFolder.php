<?php

namespace App\Models;

use Database\Factories\PrivateSaveFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-user folder that classifies the screenshots in a user's private saves.
 *
 * Rows rather than an enum column, because the three folders an account starts with are only
 * *defaults*: they carry a user-editable name, and per-user custom folders are the obvious next
 * step. Deliberately unrelated to ScreenshotCategory, which is a global, admin-curated taxonomy
 * applied to *published* Posts — this one is private, per-user, and chosen by hand at upload time.
 */
class PrivateSaveFolder extends Model
{
    /** @use HasFactory<PrivateSaveFolderFactory> */
    use HasFactory;

    /** Where a save lands when the client uploads without naming a folder. */
    public const SLUG_GENERAL = 'general';

    /**
     * Seeded for every account by EnsureDefaultPrivateSaveFolders. Array order is folder order.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        self::SLUG_GENERAL => 'General',
        'business' => 'Business',
        'memes' => 'Memes',
    ];

    protected $fillable = [
        'user_id',
        'slug',
        'name',
        'is_default',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PrivateSave, $this> */
    public function privateSaves(): HasMany
    {
        return $this->hasMany(PrivateSave::class, 'folder_id');
    }
}
