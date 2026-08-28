<?php

namespace App\Models;

use Database\Factories\PostMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * One image within a Post's carousel. `original_path` is always servable immediately
 * (already EXIF/GPS-stripped synchronously on upload); `thumbnail_path` is populated
 * later by GeneratePostMediaThumbnail — `status` tracks that, not visibility.
 */
class PostMedia extends Model
{
    /** @use HasFactory<PostMediaFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const PROCESSING_PENDING = 'pending';

    public const PROCESSING_PROCESSING = 'processing';

    public const PROCESSING_READY = 'ready';

    public const PROCESSING_FAILED = 'failed';

    public const SAFETY_CLEAR = 'clear';

    public const SAFETY_WARNING = 'warning';

    protected $fillable = [
        'post_id',
        'position',
        'original_path',
        'source_disk',
        'thumbnail_path',
        'width',
        'height',
        'mime_type',
        'size_bytes',
        'status',
        'alt_text',
        'ocr_text',
        'ocr_language',
        'ocr_status',
        'ocr_version',
        'perceptual_hash',
        'safety_status',
        'hash_status',
        'hash_version',
        'safety_version',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'ocr_text' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function originalUrl(User $viewer): string
    {
        if (str_starts_with($this->original_path, 'https://') || str_starts_with($this->original_path, 'http://')) {
            return $this->original_path;
        }

        return $this->deliveryUrl('original', $viewer);
    }

    public function thumbnailUrl(User $viewer): ?string
    {
        if ($this->thumbnail_path && (str_starts_with($this->thumbnail_path, 'https://') || str_starts_with($this->thumbnail_path, 'http://'))) {
            return $this->thumbnail_path;
        }

        return $this->thumbnail_path
            ? $this->deliveryUrl('thumbnail', $viewer)
            : null;
    }

    public function sourceDisk(): string
    {
        return $this->source_disk ?? (string) config('social.media_disk');
    }

    private function deliveryUrl(string $variant, User $viewer): string
    {
        return URL::temporarySignedRoute(
            'media.posts.show',
            now()->addSeconds((int) config('social.media_url_ttl_seconds', 1200)),
            ['media' => $this->getKey(), 'variant' => $variant, 'viewer' => $viewer->getKey()],
        );
    }
}
