<?php

namespace App\Models;

use Database\Factories\PrivateSaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * A screenshot saved privately to the user's own account — never published, never visible to
 * anyone else, no post/timeline/group involvement at all. Distinct from Collections (which
 * privately organize already-published Posts) and from MediaAnalysis (staging that always leads
 * toward a Post) — this is the "just back it up, don't share it" path, stored synchronously and
 * immediately servable, same as Post's own original images.
 */
class PrivateSave extends Model
{
    /** @use HasFactory<PrivateSaveFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'folder_id',
        'path',
        'source_disk',
        'width',
        'height',
        'mime_type',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PrivateSaveFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(PrivateSaveFolder::class, 'folder_id');
    }

    public function url(User $viewer): string
    {
        return URL::temporarySignedRoute(
            'media.private-saves.show',
            now()->addSeconds((int) config('social.media_url_ttl_seconds', 1200)),
            ['privateSave' => $this->getKey(), 'viewer' => $viewer->getKey()],
        );
    }

    public function sourceDisk(): string
    {
        return $this->source_disk ?? (string) config('social.media_disk');
    }
}
